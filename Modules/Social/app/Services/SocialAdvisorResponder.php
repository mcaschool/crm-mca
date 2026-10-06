<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Modules\Ai\Services\AdvisorTurn;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\HumanRequestDetector;
use Throwable;

/**
 * Adaptador de Instagram, Messenger y WhatsApp hacia el asesor inteligente:
 *
 *   mensaje entrante → SocialIngestService (idempotente por id del mensaje)
 *     → aquí: ¿asesor activado en el canal? ¿intervención humana? ¿horario? ¿pide una persona?
 *     → AdvisorTurnService (idempotencia persistente, mismo núcleo que la web)
 *     → remitente del canal (MetaMessageSender / WhatsAppMessageSender) → mensaje y estado.
 *
 * El asesor NO responde si: el canal no lo tiene activado o no tiene asesor, la conversación la
 * atiende una persona / está pausada / espera a una persona, el mensaje no es de texto, el canal no
 * puede enviar, el asesor está inactivo o es de otra institución, el mensaje ya se procesó, se
 * alcanzó el límite o la IA falló (nunca envía una respuesta incorrecta). Devuelve el motivo.
 */
final class SocialAdvisorResponder
{
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

    /** @return string motivo: replied | handoff | off_hours | y los de omisión (disabled, human, …) */
    public function respond(int $messageId): string
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

        // Una sola decisión por mensaje (persistente): respondido u omitido, nunca dos veces.
        $institutionId = (int) $conversation->institution_id;
        $provider = (string) $conversation->provider;
        $externalId = (string) $message->external_message_id;
        if ($this->turns->alreadyHandled($institutionId, $provider, $externalId)) {
            return 'duplicate';
        }
        $skip = fn (string $reason): string => $this->turns->recordSkipped($institutionId, $provider, $externalId) ? $reason : 'duplicate';

        if (! $conversation->advisorMayReply()) {
            return $skip('human');
        }
        if ($message->type !== 'text' || trim((string) $message->body) === '') {
            return $skip('not_text');
        }
        // Nunca se contesta un mensaje viejo: solo el ÚLTIMO entrante de la conversación y con
        // menos de 15 minutos (un reintento tardío o un mensaje que llegó mientras atendía una
        // persona no recibe respuesta automática después).
        if ($this->isStale($conversation, $message)) {
            return $skip('stale');
        }
        if (! $channel->hasSender()) {
            return $skip('no_sender'); // sin remitente no se consulta a la IA (no se gasta ni se pierde)
        }

        // Fuera del horario de atención automática: aviso (una vez cada 12 h), sin IA.
        if (! $channel->withinAdvisorSchedule()) {
            return $skip('off_hours') === 'duplicate' ? 'duplicate' : $this->offHours($conversation, $channel);
        }

        // La persona pide hablar con alguien del equipo: se transfiere sin consultar al asesor.
        if ($channel->advisor_handoff_enabled && $this->humanRequests->wantsPerson($message->body)) {
            if ($skip('handoff') === 'duplicate') {
                return 'duplicate';
            }
            $this->automation->waitForPerson($conversation);
            $text = trim((string) $channel->advisor_handoff_message);
            $this->outbound->sendFromAdvisor($conversation, $text !== '' ? $text : __('Te pongo en contacto con una persona del equipo. Te responderá por aquí en cuanto esté disponible.'));

            return 'handoff';
        }

        // Espera antes de responder (tras confirmar el webhook) y revalidación: si mientras tanto
        // respondió una persona o tomó la conversación, el asesor no contesta.
        if ($channel->advisor_reply_delay > 0) {
            Sleep::for(min((int) $channel->advisor_reply_delay, 30))->seconds();
            $conversation->refresh();
            if (! $conversation->advisorMayReply() || $this->personRepliedAfter($conversation, $message)) {
                return $skip('human');
            }
        }

        try {
            $result = $this->turns->process(new AdvisorTurn(
                institutionId: (int) $conversation->institution_id,
                botId: (int) $channel->advisor_bot_id,
                channel: (string) $conversation->provider,
                externalConversationId: (string) $conversation->external_conversation_id,
                text: (string) $message->body,
                externalMessageId: (string) $message->external_message_id,
                metadata: ['social_conversation_id' => $conversation->getKey(), 'social_message_id' => $message->getKey()],
            ));
        } catch (InvalidArgumentException $e) {
            // Asesor inactivo, de otra institución o canal no admitido: no se responde.
            Log::info('social.advisor: turno no admitido', ['conversation_id' => $conversation->getKey(), 'reason' => $e->getMessage()]);

            return 'not_allowed';
        } catch (Throwable $e) {
            Log::warning('social.advisor: error inesperado', ['conversation_id' => $conversation->getKey(), 'error' => $e->getMessage()]);

            return 'error';
        }

        return match ($result->status) {
            'duplicate' => 'duplicate',
            'human_active' => 'human',
            // La IA falló o no hay proveedor: nunca se envía una respuesta incorrecta al canal.
            'unavailable' => 'ai_unavailable',
            // Límite de la conversación: no se responde y pasa a una persona.
            'limit_reached' => $this->toPerson($conversation, 'limit'),
            'handoff' => $this->sendThenWait($conversation, (string) $result->reply),
            default => $this->send($conversation, (string) $result->reply),
        };
    }

    private function send(SocialConversation $conversation, string $reply): string
    {
        if (trim($reply) === '') {
            return 'empty';
        }
        $this->outbound->sendFromAdvisor($conversation, $reply);

        return 'replied';
    }

    private function sendThenWait(SocialConversation $conversation, string $reply): string
    {
        $this->send($conversation, $reply);
        $this->automation->waitForPerson($conversation);

        return 'handoff';
    }

    private function toPerson(SocialConversation $conversation, string $reason): string
    {
        $this->automation->waitForPerson($conversation);

        return $reason;
    }

    private function offHours(SocialConversation $conversation, SocialChannel $channel): string
    {
        $text = trim((string) $channel->advisor_off_hours_message);
        $notified = $conversation->advisor_off_hours_notified_at;
        if ($text === '' || ($notified !== null && $notified->gt(now()->subHours(12)))) {
            return 'off_hours';
        }

        $this->outbound->sendFromAdvisor($conversation, $text);
        $conversation->advisor_off_hours_notified_at = now();
        $conversation->save();

        return 'off_hours';
    }

    private function isStale(SocialConversation $conversation, SocialMessage $message): bool
    {
        $newer = SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'inbound')
            ->where('id', '>', $message->getKey())
            ->exists();
        $at = $message->provider_timestamp ?? $message->created_at;

        return $newer || ($at !== null && $at->lt(now()->subMinutes(15)));
    }

    private function personRepliedAfter(SocialConversation $conversation, SocialMessage $inbound): bool
    {
        return SocialMessage::query()
            ->where('social_conversation_id', $conversation->getKey())
            ->where('direction', 'outbound')
            ->where('sender_type', 'agent')
            ->where('id', '>', $inbound->getKey())
            ->exists();
    }
}
