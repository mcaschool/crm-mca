<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Ai\Events\AdvisorTurnHandled;
use Modules\Ai\Models\AdvisorMessageReceipt;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Crm\Services\ConversationService;
use Modules\Crm\Services\MessageService;
use Modules\Institutions\Models\Bot;
use Throwable;

/**
 * PUNTO ÚNICO de entrada del asesor inteligente para TODOS los canales:
 *
 *   Canal (Web Chat · prueba · Instagram · Messenger · WhatsApp)
 *     → AdvisorTurnService → CeliaService → capa IA común (AiProcessResolver → AiChatClient)
 *
 * No es un segundo motor: la respuesta, el conocimiento, el prompt por asesor, el modelo, el
 * registro de uso y las alertas son los de CeliaService y la capa común.
 *
 * Garantías:
 *  - institution_id: todo corre en el contexto de la institución del turno; un asesor de otra
 *    institución no existe.
 *  - Memoria separada por asesor + canal + conversación externa. En Web Chat la conversación es
 *    la del widget (session_id): se conservan sesión, contacto, leads, eventos y límites.
 *  - Idempotencia PERSISTENTE por mensaje externo: índice único en advisor_message_receipts
 *    (institución + canal + id del mensaje); el bloqueo de caché es una protección adicional.
 *  - Traspaso a persona (mode = human): el asesor no responde en paralelo.
 *  - Pruebas: el canal 'preview' es siempre is_test y viceversa; un asesor inactivo solo atiende
 *    en pruebas. CeliaService no deja rastro comercial en pruebas.
 *  - Cada turno emite AdvisorTurnHandled (trazabilidad del canal de origen).
 */
class AdvisorTurnService
{
    public const TEST_CHANNEL = 'preview';

    public const WEB_CHANNEL = 'web';

    /** Canales que la capa común admite. */
    public const CHANNELS = [self::WEB_CHANNEL, self::TEST_CHANNEL, 'instagram', 'messenger', 'whatsapp'];

    public function __construct(
        private readonly CeliaService $celia,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly CurrentInstitution $tenancy,
    ) {}

    /**
     * Abre (o retoma) la conversación del asesor en un canal y devuelve el saludo (plantilla, sin
     * IA). En Web Chat saluda SIEMPRE que la persona activa al asesor (comportamiento del widget);
     * en otros canales solo si la conversación es nueva.
     */
    public function open(int $institutionId, int $botId, string $channel, string $externalConversationId, bool $isTest = false, string $locale = 'es', ?int $contactId = null): AdvisorTurnResult
    {
        return $this->tenancy->runFor($institutionId, function () use ($institutionId, $botId, $channel, $externalConversationId, $isTest, $locale, $contactId): AdvisorTurnResult {
            $bot = $this->bot($botId, $isTest);
            [$conversation, $created] = $this->conversationFor($bot, $channel, $externalConversationId, $isTest, $locale, $contactId);

            if (! $created && $channel !== self::WEB_CHANNEL && $conversation->messages()->exists()) {
                $last = $this->lastAdvisorMessage($conversation);
                $result = new AdvisorTurnResult(
                    status: 'answered', reply: $last?->content, intent: 'resume', handoff: $conversation->mode === 'human',
                    conversationId: (int) $conversation->getKey(), replyMessageId: $last?->getKey(),
                );
            } else {
                $response = $this->celia->greet($conversation, $this->contact($conversation), $locale);
                $result = $this->toResult($conversation, $response, $this->lastAdvisorMessage($conversation));
            }

            AdvisorTurnHandled::dispatch($institutionId, (int) $bot->getKey(), $channel, (int) $conversation->getKey(), $result->status, $isTest, 'open');

            return $result;
        });
    }

    /** Procesa un turno del usuario y devuelve lo que el adaptador debe hacer en su canal. */
    public function process(AdvisorTurn $turn): AdvisorTurnResult
    {
        if (trim($turn->text) === '') {
            throw new InvalidArgumentException('El turno no tiene texto.');
        }

        return $this->tenancy->runFor($turn->institutionId, function () use ($turn): AdvisorTurnResult {
            $bot = $this->bot($turn->botId, $turn->isTest);
            [$conversation] = $this->conversationFor($bot, $turn->channel, $turn->externalConversationId, $turn->isTest, $turn->locale, $turn->contactId);

            $result = $turn->externalMessageId === null
                ? $this->runTurn($conversation, $turn)
                : Cache::lock('advisor-turn:'.$turn->institutionId.':'.$turn->channel.':'.sha1($turn->externalMessageId), 60)
                    ->block(20, fn (): AdvisorTurnResult => $this->claimAndRun($conversation, $turn));

            AdvisorTurnHandled::dispatch($turn->institutionId, (int) $bot->getKey(), $turn->channel, (int) $conversation->getKey(), $result->status, $turn->isTest, 'message');

            return $result;
        });
    }

    /** ¿Ya hay una decisión (respuesta u omisión) para este mensaje externo? */
    public function alreadyHandled(int $institutionId, string $channel, string $externalMessageId): bool
    {
        return $this->tenancy->runFor($institutionId, fn (): bool => AdvisorMessageReceipt::query()
            ->where('channel', $channel)->where('external_message_id', $externalMessageId)->exists());
    }

    /**
     * El adaptador decidió NO consultar al asesor para este mensaje (persona atendiendo, fuera de
     * horario, transferencia pedida…): se registra con el mismo índice único para que el mensaje
     * no se atienda nunca dos veces. Devuelve false si ya estaba registrado (duplicado).
     */
    public function recordSkipped(int $institutionId, string $channel, string $externalMessageId): bool
    {
        return $this->tenancy->runFor($institutionId, function () use ($channel, $externalMessageId): bool {
            try {
                AdvisorMessageReceipt::query()->create(['channel' => $channel, 'external_message_id' => $externalMessageId, 'status' => 'skipped']);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        });
    }

    /** Traspasa la conversación a una persona: desde aquí el asesor no responde en paralelo. */
    public function handOff(Conversation $conversation): void
    {
        $this->conversations->switchMode($conversation, 'human');
    }

    /** Devuelve la conversación al asesor inteligente tras la atención humana. */
    public function releaseToAdvisor(Conversation $conversation): void
    {
        $this->conversations->switchMode($conversation, 'celia');
    }

    /**
     * Conversación del asesor ligada a una conversación externa de un canal (o null si aún no
     * existe). La usan los adaptadores para sincronizar el traspaso a persona.
     */
    public function findConversation(int $botId, string $channel, string $externalConversationId): ?Conversation
    {
        return Conversation::query()
            ->where('bot_id', $botId)
            ->where('channel', $channel)
            ->where('external_id', $externalConversationId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Reclama el mensaje externo de forma persistente (índice único) y solo entonces lo procesa.
     * Si otro proceso ya lo reclamó (reintento o entrega simultánea), devuelve 'duplicate' sin
     * llamar a la IA ni crear mensajes. Si el procesamiento falla de forma inesperada, se libera
     * el reclamo para que un reintento pueda atenderlo.
     */
    private function claimAndRun(Conversation $conversation, AdvisorTurn $turn): AdvisorTurnResult
    {
        try {
            $receipt = AdvisorMessageReceipt::query()->create([
                'channel' => $turn->channel,
                'external_message_id' => (string) $turn->externalMessageId,
                'conversation_id' => $conversation->getKey(),
                'status' => 'processing',
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateOf($conversation, $turn);
        }

        try {
            $result = $this->runTurn($conversation, $turn);
        } catch (Throwable $e) {
            $receipt->delete();

            throw $e;
        }

        $receipt->forceFill(['status' => 'done', 'reply_message_id' => $result->replyMessageId])->save();

        return $result;
    }

    private function duplicateOf(Conversation $conversation, AdvisorTurn $turn): AdvisorTurnResult
    {
        $receipt = AdvisorMessageReceipt::query()
            ->where('channel', $turn->channel)
            ->where('external_message_id', (string) $turn->externalMessageId)
            ->first();
        $reply = $receipt?->reply_message_id !== null ? Message::query()->find($receipt->reply_message_id) : null;

        return new AdvisorTurnResult(
            status: 'duplicate', reply: $reply?->content, intent: 'duplicate', handoff: $conversation->mode === 'human',
            conversationId: (int) $conversation->getKey(), replyMessageId: $reply?->getKey(),
            sources: $this->sourcesOf($reply),
        );
    }

    private function runTurn(Conversation $conversation, AdvisorTurn $turn): AdvisorTurnResult
    {
        if ($conversation->mode === 'human') {
            $this->messages->record($conversation, 'user', $turn->text, 'text', [], $turn->externalMessageId);

            return new AdvisorTurnResult(
                status: 'human_active', reply: null, intent: 'handoff', handoff: true,
                conversationId: (int) $conversation->getKey(),
            );
        }

        if ($conversation->mode !== 'celia') {
            $this->conversations->switchMode($conversation, 'celia');
        }

        $response = $this->celia->handle($conversation, $this->contact($conversation), $turn->text, $turn->locale, $turn->externalMessageId, $turn->retryOnAiFailure);

        return $this->toResult($conversation, $response, $this->lastAdvisorMessage($conversation));
    }

    /**
     * Asesor de la institución del contexto (otra institución → no existe). Fuera de pruebas,
     * solo un asesor ACTIVO atiende.
     */
    private function bot(int $botId, bool $isTest): Bot
    {
        $bot = Bot::query()->find($botId);
        if ($bot === null) {
            throw new InvalidArgumentException('Asesor no encontrado en esta institución.');
        }
        if (! $isTest && $bot->status !== 'active') {
            throw new InvalidArgumentException('El asesor no está activo.');
        }

        return $bot;
    }

    /** @return array{0: Conversation, 1: bool} [conversación, recién creada] */
    private function conversationFor(Bot $bot, string $channel, string $externalId, bool $isTest, string $locale, ?int $contactId): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Canal no admitido: {$channel}.");
        }
        if (($channel === self::TEST_CHANNEL) !== $isTest) {
            throw new InvalidArgumentException('El canal de prueba solo admite conversaciones de prueba, y una prueba solo va por ese canal.');
        }
        if (trim($externalId) === '') {
            throw new InvalidArgumentException('Falta el id de la conversación en el canal.');
        }

        // Web Chat: la conversación es la del widget (sesión), creada por su endpoint de sesión.
        if ($channel === self::WEB_CHANNEL) {
            $web = Conversation::query()
                ->where('bot_id', $bot->getKey())
                ->where('session_id', $externalId)
                ->where('is_test', false)
                ->first();
            if ($web === null) {
                throw new InvalidArgumentException('Sesión de Web Chat no encontrada.');
            }

            return [$web, false];
        }

        $existing = $this->findConversation((int) $bot->getKey(), $channel, $externalId);
        if ($existing !== null) {
            return [$existing, false];
        }

        return [$this->conversations->start([
            'bot_id' => $bot->getKey(),
            'channel' => $channel,
            'is_test' => $isTest,
            'external_id' => $externalId,
            'mode' => 'celia',
            'language' => in_array($locale, ['es', 'en'], true) ? $locale : 'es',
            // Una prueba nunca se vincula a un contacto real.
            'contact_id' => $isTest ? null : $contactId,
        ]), true];
    }

    private function contact(Conversation $conversation): ?Contact
    {
        return $conversation->contact_id === null ? null : Contact::query()->find($conversation->contact_id);
    }

    private function lastAdvisorMessage(Conversation $conversation): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('sender_type', 'celia')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<int, string> */
    private function sourcesOf(?Message $message): array
    {
        $sources = $message?->meta['knowledge_sources'] ?? [];

        return is_array($sources) ? array_values(array_map('strval', $sources)) : [];
    }

    /** @param  array<string, mixed>  $response  respuesta de CeliaService */
    private function toResult(Conversation $conversation, array $response, ?Message $reply): AdvisorTurnResult
    {
        $action = (string) ($response['action'] ?? 'answer');
        $status = match ($action) {
            'unresolved' => 'unresolved',
            'limit' => 'limit_reached',
            'unavailable' => 'unavailable',
            'handoff' => 'handoff',
            default => 'answered',
        };

        return new AdvisorTurnResult(
            status: $status,
            reply: isset($response['reply']) ? (string) $response['reply'] : null,
            intent: $action,
            // El asesor pide pasar a una persona (sus reglas de transferencia). El adaptador del
            // canal decide cómo (en redes: «Esperando a una persona»).
            handoff: $action === 'handoff',
            conversationId: (int) $conversation->getKey(),
            replyMessageId: $reply?->getKey(),
            sources: $this->sourcesOf($reply),
            usedAi: (bool) ($response['used_ai'] ?? false),
            adapter: [
                'channel' => $conversation->channel,
                'language' => $conversation->language,
                'messages_left' => (int) ($response['messages_left'] ?? 0),
                'limit_reached' => (bool) ($response['limit_reached'] ?? false),
            ],
        );
    }
}
