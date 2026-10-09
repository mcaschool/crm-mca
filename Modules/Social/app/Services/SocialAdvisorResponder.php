<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Ai\Services\AdvisorTurn;
use Modules\Ai\Services\AdvisorTurnResult;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Ai\Services\AiProviderException;
use Modules\Crm\Models\Message;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\HumanRequestDetector;
use Throwable;

/**
 * Adaptador de Instagram, Messenger y WhatsApp hacia el asesor inteligente:
 *
 *   mensaje entrante → SocialIngestService (idempotente por id del mensaje)
 *     → aquí: ¿asesor activado en la cuenta? ¿turno libre? ¿intervención humana? ¿horario?
 *     → AdvisorTurnService (idempotencia persistente, MISMO núcleo que el Web Chat)
 *     → remitente oficial del canal (MetaMessageSender / WhatsAppMessageSender) → mensaje y estado.
 *
 * El asesor NO responde si: la cuenta no lo tiene activado o no tiene asesor, la conversación la
 * atiende una persona / está pausada / en error / espera a una persona, el mensaje es anterior a
 * la última reactivación, ya hay un mensaje más nuevo, ya se procesó, o el canal no puede enviar.
 * Nunca envía una respuesta incorrecta: ante un fallo no recuperable, la conversación pasa a
 * «Error de automatización» con un motivo visible y se avisa a los administradores.
 *
 * Se ejecuta desde la cola persistente (RespondWithAdvisor), nunca dentro del webhook. Un fallo
 * pasajero (IA saturada, tiempo agotado, error inesperado) devuelve RETRY sin dejar rastro y el
 * job reintenta; en el último intento la conversación pasa a error. Si otro worker está atendiendo
 * la misma conversación, devuelve BUSY y el job vuelve a la cola en unos segundos.
 */
final class SocialAdvisorResponder
{
    /** Fallo pasajero: el job debe volver a la cola. */
    public const RETRY = 'retry';

    /** Otro worker tiene el turno de esta conversación: volver a la cola enseguida. */
    public const BUSY = 'busy';

    /** Antigüedad máxima de un mensaje para recibir respuesta automática. */
    private const MAX_AGE_MINUTES = 15;

    /** Categorías de IA en las que reintentar tiene sentido. */
    private const RETRYABLE = ['ai_timeout', 'ai_rate_limited', 'ai_error', 'internal_error'];

    public function __construct(
        private readonly AdvisorTurnService $turns,
        private readonly SocialOutboundService $outbound,
        private readonly SocialAutomationService $automation,
        private readonly HumanRequestDetector $humanRequests,
    ) {}

    /** ¿Merece la pena despachar el asesor para este canal? (filtro barato antes de la cola) */
    public static function channelIsAutomated(SocialChannel $channel): bool
    {
        return $channel->advisor_enabled && $channel->advisor_bot_id !== null;
    }

    /** @return string motivo: replied | handoff | off_hours | retry | busy | gave_up | y los de omisión (disabled, human, …) */
    public function respond(int $messageId, bool $finalAttempt = true): string
    {
        $message = SocialMessage::query()->find($messageId);
        $conversation = $message !== null ? SocialConversation::query()->with('channel')->find($message->social_conversation_id) : null;
        $channel = $conversation?->channel;

        if ($message === null || $conversation === null || $channel === null) {
            return 'missing';
        }
        if ($message->direction !== 'inbound' || $message->sender_type !== 'contact') {
            return 'not_inbound';
        }
        if (! self::channelIsAutomated($channel) || ! $channel->is_active) {
            return 'disabled';
        }
        if ($this->turns->alreadyHandled((int) $conversation->institution_id, (string) $conversation->provider, (string) $message->external_message_id)) {
            return 'duplicate';
        }

        // Un solo worker por conversación (turno persistente en BD, caduca solo si el worker muere).
        // Si es el ÚLTIMO intento y sigue ocupado (no debería: el turno caduca a los 180 s y las
        // esperas de BUSY suman más), no se reintenta más: pasa a error visible, nunca en bucle.
        if (! $this->automation->claim($conversation)) {
            return $finalAttempt ? $this->giveUp((int) $message->getKey(), 'conversation_busy') : self::BUSY;
        }

        try {
            return $this->handle($conversation, $channel, $message, $finalAttempt);
        } finally {
            $this->automation->release($conversation);
        }
    }

    /**
     * Sin respuesta automática para este mensaje (agotados los intentos por una excepción): se
     * registra la decisión (nunca se reintenta después) y la conversación pasa a error con aviso.
     */
    public function giveUp(int $messageId, string $reason = 'internal_error'): string
    {
        $message = SocialMessage::query()->find($messageId);
        $conversation = $message !== null ? SocialConversation::query()->with('channel')->find($message->social_conversation_id) : null;
        if ($message === null || $conversation === null) {
            return 'missing';
        }
        if (! $this->turns->recordSkipped((int) $conversation->institution_id, (string) $conversation->provider, (string) $message->external_message_id)) {
            return 'duplicate';
        }
        if ($conversation->advisorMayReply()) {
            $this->automation->fail($conversation, $reason);
        }

        return 'gave_up';
    }

    private function handle(SocialConversation $conversation, SocialChannel $channel, SocialMessage $message, bool $finalAttempt): string
    {
        // Una sola decisión por mensaje (persistente): respondido u omitido, nunca dos veces.
        $institutionId = (int) $conversation->institution_id;
        $provider = (string) $conversation->provider;
        $skip = fn (string $reason): string => $this->turns->recordSkipped($institutionId, $provider, (string) $message->external_message_id) ? $reason : 'duplicate';

        $conversation->refresh(); // estado actual (otro worker o una persona pudo cambiarlo)
        if (! $conversation->advisorMayReply()) {
            return $skip('human');
        }
        // Activar la cuenta (o reasignarla) no contesta lo antiguo: solo mensajes llegados después.
        if ($channel->advisor_assigned_at !== null && $message->created_at !== null && $message->created_at->lt($channel->advisor_assigned_at)) {
            return $skip('before_activation');
        }
        // Reactivar no contesta lo antiguo: solo mensajes llegados DESPUÉS del último cambio de estado.
        if ($conversation->automation_changed_at !== null && $message->created_at !== null && $message->created_at->lt($conversation->automation_changed_at)) {
            return $skip('before_reactivation');
        }
        // Nunca se contesta un mensaje viejo ni uno con otro más nuevo detrás (ese lo contestará
        // su propio job, agrupando a este).
        if ($this->isStale($conversation, $message)) {
            return $skip('stale');
        }
        if ($message->type !== 'text' || trim((string) $message->body) === '') {
            return $this->unsupported($conversation, $channel, $message, $skip);
        }
        // Sin conexión utilizable no se consulta a la IA (no se gasta ni se finge un envío).
        if (! $channel->automationCanSend()) {
            return $skip('channel_disconnected') === 'duplicate' ? 'duplicate' : $this->stop($conversation, 'channel_disconnected');
        }

        // Fuera del horario de atención automática: aviso (una vez cada 12 h), sin IA.
        if (! $channel->withinAdvisorSchedule()) {
            return $skip('off_hours') === 'duplicate' ? 'duplicate' : $this->offHours($conversation, $channel, $message);
        }

        // La persona pide hablar con alguien del equipo: se transfiere sin consultar al asesor.
        if ($channel->advisor_handoff_enabled && $this->humanRequests->wantsPerson($message->body)) {
            if ($skip('handoff') === 'duplicate') {
                return 'duplicate';
            }
            $this->sendHandoffMessage($conversation, $channel, $message);
            $this->automation->waitForPerson($conversation, 'contact_requested_person');

            return 'handoff';
        }

        // La espera se aplicó como retraso del job: si mientras tanto respondió una persona del
        // equipo, el asesor no contesta.
        if ($this->personRepliedAfter($conversation, $message)) {
            return $skip('human');
        }

        $startedAt = microtime(true);
        try {
            $result = $this->turns->process(new AdvisorTurn(
                institutionId: $institutionId,
                botId: (int) $channel->advisor_bot_id,
                channel: $provider,
                externalConversationId: (string) $conversation->external_conversation_id,
                // Varios mensajes seguidos sin respuesta se atienden juntos, en una sola respuesta.
                text: $this->groupedText($conversation, $message),
                externalMessageId: (string) $message->external_message_id,
                metadata: ['social_conversation_id' => $conversation->getKey(), 'social_message_id' => $message->getKey()],
                retryOnAiFailure: true,
            ));
        } catch (InvalidArgumentException $e) {
            // Asesor inactivo, de otra institución o canal no admitido: no se responde.
            Log::info('social.advisor: turno no admitido', ['conversation_id' => $conversation->getKey(), 'reason' => $e->getMessage()]);

            return $skip('advisor_inactive') === 'duplicate' ? 'duplicate' : $this->stop($conversation, 'advisor_inactive');
        } catch (Throwable $e) {
            // IA caída (AdvisorAiUnavailable) o error inesperado. El turno se deshizo (sin recibo ni
            // respuesta): se reintenta si tiene sentido o pasa a error con su motivo.
            $reason = $this->classify($e);
            Log::warning('social.advisor: fallo al responder', ['conversation_id' => $conversation->getKey(), 'final' => $finalAttempt, 'reason' => $reason, 'error' => class_basename($e)]);

            return in_array($reason, self::RETRYABLE, true) && ! $finalAttempt
                ? self::RETRY
                : $this->giveUp((int) $message->getKey(), $reason);
        }

        $trace = $this->trace($channel, $message, $result, $startedAt);

        return match ($result->status) {
            'duplicate' => 'duplicate',
            'human_active' => 'human',
            // No hay proveedor de IA configurado: nunca se envía una respuesta incorrecta.
            'unavailable' => $this->stop($conversation, 'ai_not_configured'),
            // Límite de la conversación: no se responde y pasa a una persona.
            'limit_reached' => $this->toPerson($conversation, 'limit_reached'),
            // El asesor transfiere (sus reglas) o no encuentra la respuesta en sus fuentes: contesta
            // con lo que tiene (sin inventar) y la conversación queda a la espera de una persona.
            'handoff' => $this->sendThenWait($conversation, (string) $result->reply, $trace, 'advisor_handoff'),
            'unresolved' => $this->sendThenWait($conversation, (string) $result->reply, $trace, 'unresolved'),
            default => $this->send($conversation, (string) $result->reply, $trace),
        };
    }

    /**
     * Adjunto (imagen, audio, vídeo, documento…): el asesor no lo analiza ni inventa su contenido.
     * Queda registrado en la bandeja y la conversación pasa a una persona (con el mensaje de
     * transferencia del canal). Un sticker suelto no transfiere: simplemente no se contesta.
     *
     * @param  callable(string): string  $skip
     */
    private function unsupported(SocialConversation $conversation, SocialChannel $channel, SocialMessage $message, callable $skip): string
    {
        if ($skip('not_text') === 'duplicate') {
            return 'duplicate';
        }
        if ($message->type === 'sticker') {
            return 'not_text';
        }
        if ($channel->automationCanSend()) {
            $this->sendHandoffMessage($conversation, $channel, $message);
        }
        $this->automation->waitForPerson($conversation, 'unsupported_attachment');

        return 'unsupported_attachment';
    }

    /** @param  array{ai_bot_id: int|null, in_reply_to_id: int, ai_meta: array<string, mixed>}  $trace */
    private function send(SocialConversation $conversation, string $reply, array $trace): string
    {
        if (trim($reply) === '') {
            return 'empty';
        }
        $sent = $this->outbound->sendFromAdvisor($conversation, $reply, $trace);
        if ($sent->status === 'sent') {
            return 'replied';
        }

        // El proveedor no la aceptó: queda como fallida (nunca «enviada») y se detiene la automatización.
        $meta = (array) $sent->ai_meta;

        return $this->stop($conversation, match (true) {
            $sent->status === 'failed_window' => 'window_closed',
            // Pudo llegar: nunca se reenvía; una persona lo revisa.
            $sent->status === 'delivery_unknown' => 'delivery_unknown',
            (bool) ($meta['send_token_invalid'] ?? false) => 'social_token_invalid',
            default => 'send_failed',
        });
    }

    /** @param  array{ai_bot_id: int|null, in_reply_to_id: int, ai_meta: array<string, mixed>}  $trace */
    private function sendThenWait(SocialConversation $conversation, string $reply, array $trace, string $reason): string
    {
        $outcome = $this->send($conversation, $reply, $trace);
        if ($outcome === 'replied' || $outcome === 'empty') {
            $this->automation->waitForPerson($conversation, $reason);

            return $reason === 'unresolved' ? 'unresolved' : 'handoff';
        }

        return $outcome;
    }

    private function toPerson(SocialConversation $conversation, string $reason): string
    {
        $this->automation->waitForPerson($conversation, $reason);

        return $reason;
    }

    /** Fallo no recuperable: «Error de automatización» con motivo visible y aviso crítico. */
    private function stop(SocialConversation $conversation, string $reason): string
    {
        $this->automation->fail($conversation, $reason);

        return $reason;
    }

    private function offHours(SocialConversation $conversation, SocialChannel $channel, SocialMessage $message): string
    {
        $text = trim((string) $channel->advisor_off_hours_message);
        $notified = $conversation->advisor_off_hours_notified_at;
        if ($text === '' || ($notified !== null && $notified->gt(now()->subHours(12)))) {
            return 'off_hours';
        }

        $this->outbound->sendFromAdvisor($conversation, $text, $this->plainTrace($channel, $message, 'off_hours'));
        $conversation->advisor_off_hours_notified_at = now();
        $conversation->save();

        return 'off_hours';
    }

    private function sendHandoffMessage(SocialConversation $conversation, SocialChannel $channel, SocialMessage $message): void
    {
        $text = trim((string) $channel->advisor_handoff_message);
        $this->outbound->sendFromAdvisor(
            $conversation,
            $text !== '' ? $text : __('Te pongo en contacto con una persona del equipo. Te responderá por aquí en cuanto esté disponible.'),
            $this->plainTrace($channel, $message, 'handoff'),
        );
    }

    /**
     * Texto del turno: los mensajes de texto que la persona envió seguidos desde la última
     * respuesta (máximo social.advisor.group_max_messages, y solo los recientes y posteriores a la
     * última reactivación), en orden. Así no se contesta por separado a cada fragmento.
     */
    private function groupedText(SocialConversation $conversation, SocialMessage $message): string
    {
        $max = max(1, (int) config('social.advisor.group_max_messages', 5));
        $lastOutbound = (int) SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'outbound')
            ->max('id');
        $since = now()->subMinutes(self::MAX_AGE_MINUTES);
        if ($conversation->automation_changed_at !== null && $conversation->automation_changed_at->gt($since)) {
            $since = $conversation->automation_changed_at;
        }

        $parts = SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'inbound')
            ->where('sender_type', 'contact')
            ->where('type', 'text')
            ->where('id', '>', $lastOutbound)
            ->where('id', '<=', $message->getKey())
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit($max)
            ->pluck('body')
            ->reverse()
            ->map(fn ($body): string => trim((string) $body))
            ->filter(fn (string $body): bool => $body !== '')
            ->values();

        return $parts->isNotEmpty() ? $parts->implode("\n") : (string) $message->body;
    }

    /**
     * Fallo → motivo seguro. Busca en la cadena la excepción NORMALIZADA de la capa de IA
     * (AiProviderException con su categoría); cualquier otra cosa es un error interno.
     */
    private function classify(Throwable $e): string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AiProviderException) {
                return match ($cause->category) {
                    AiErrorCategory::AuthenticationError => 'ai_auth',
                    AiErrorCategory::QuotaExhausted => 'ai_quota',
                    AiErrorCategory::ModelUnavailable => 'ai_model_unavailable',
                    AiErrorCategory::RateLimited => 'ai_rate_limited',
                    AiErrorCategory::Timeout => 'ai_timeout',
                    AiErrorCategory::InvalidRequest => 'ai_model_unavailable',
                    AiErrorCategory::ProviderUnavailable, AiErrorCategory::Unknown => 'ai_error',
                };
            }
        }

        return 'internal_error';
    }

    /**
     * Trazabilidad de la respuesta de IA (sin contenido, razonamiento ni secretos): asesor, mensaje
     * que la originó, proveedor, modelo, tokens, latencia de la IA y duración total del turno.
     *
     * @return array{ai_bot_id: int|null, in_reply_to_id: int, ai_meta: array<string, mixed>}
     */
    private function trace(SocialChannel $channel, SocialMessage $message, AdvisorTurnResult $result, float $startedAt): array
    {
        $meta = $result->replyMessageId !== null ? (array) (Message::query()->find($result->replyMessageId)->meta ?? []) : [];
        $safe = array_intersect_key($meta, array_flip(['provider', 'model', 'latency_ms', 'input_tokens', 'output_tokens', 'reasoning_tokens']));

        return [
            'ai_bot_id' => $channel->advisor_bot_id,
            'in_reply_to_id' => (int) $message->getKey(),
            'ai_meta' => array_merge($safe, [
                'kind' => $result->status,
                'used_ai' => $result->usedAi,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'sources' => count($result->sources),
            ]),
        ];
    }

    /** @return array{ai_bot_id: int|null, in_reply_to_id: int, ai_meta: array<string, mixed>} */
    private function plainTrace(SocialChannel $channel, SocialMessage $message, string $kind): array
    {
        return ['ai_bot_id' => $channel->advisor_bot_id, 'in_reply_to_id' => (int) $message->getKey(), 'ai_meta' => ['kind' => $kind, 'used_ai' => false]];
    }

    private function isStale(SocialConversation $conversation, SocialMessage $message): bool
    {
        $newer = SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'inbound')
            ->where('id', '>', $message->getKey())
            ->exists();
        $at = $message->provider_timestamp ?? $message->created_at;

        return $newer || ($at !== null && $at->lt(now()->subMinutes(self::MAX_AGE_MINUTES)));
    }

    private function personRepliedAfter(SocialConversation $conversation, SocialMessage $inbound): bool
    {
        return SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'outbound')
            ->whereIn('sender_type', ['agent', 'app'])
            ->where('id', '>', $inbound->getKey())
            ->exists();
    }
}
