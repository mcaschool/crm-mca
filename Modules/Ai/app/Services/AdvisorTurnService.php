<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Crm\Services\ConversationService;
use Modules\Crm\Services\MessageService;
use Modules\Institutions\Models\Bot;

/**
 * Capa COMÚN del asesor inteligente por canal: un único punto para procesar un turno
 * conversacional, sea cual sea el canal (modo de prueba hoy; Web Chat, Instagram, Messenger y
 * WhatsApp en la siguiente etapa). NO es un segundo motor de IA: la respuesta, el conocimiento,
 * el prompt, el modelo, el registro de uso y las alertas son los de CeliaService y la capa común
 * AiProcessResolver → AiChatClient.
 *
 * Garantías:
 *  - institution_id: todo corre dentro del contexto de la institución del turno; un asesor de
 *    otra institución no se encuentra.
 *  - Memoria separada por asesor + canal + conversación externa (conversations.external_id).
 *  - Idempotencia por mensaje externo (messages.external_id + bloqueo): un reintento devuelve la
 *    respuesta ya dada sin volver a llamar a la IA ni duplicar mensajes.
 *  - Traspaso a persona (mode = human): el bot deja de responder; el mensaje queda registrado.
 *  - Pruebas: el canal 'preview' es siempre is_test y viceversa; CeliaService no deja rastro
 *    comercial (eventos, leads, intereses) en conversaciones de prueba. Un asesor inactivo solo
 *    se puede usar en pruebas.
 *  - Trazabilidad: canal de origen en la conversación; fuentes usadas en el meta del mensaje.
 */
final class AdvisorTurnService
{
    public const TEST_CHANNEL = 'preview';

    /** Canales que la capa común admite (los sociales aún sin adaptador conectado). */
    public const CHANNELS = ['web', self::TEST_CHANNEL, 'instagram', 'messenger', 'whatsapp'];

    public function __construct(
        private readonly CeliaService $celia,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly CurrentInstitution $tenancy,
    ) {}

    /**
     * Abre (o retoma) la conversación del asesor en un canal. Si es nueva, devuelve el saludo del
     * asesor (plantilla, sin IA); si ya existía, su última respuesta.
     */
    public function open(int $institutionId, int $botId, string $channel, string $externalConversationId, bool $isTest = false, string $locale = 'es', ?int $contactId = null): AdvisorTurnResult
    {
        return $this->tenancy->runFor($institutionId, function () use ($botId, $channel, $externalConversationId, $isTest, $locale, $contactId): AdvisorTurnResult {
            $bot = $this->bot($botId, $isTest);
            [$conversation, $created] = $this->conversationFor($bot, $channel, $externalConversationId, $isTest, $locale, $contactId);

            if (! $created && $conversation->messages()->exists()) {
                $last = $this->lastAdvisorMessage($conversation);

                return new AdvisorTurnResult(
                    status: 'answered', reply: $last?->content, intent: 'resume', handoff: $conversation->mode === 'human',
                    conversationId: (int) $conversation->getKey(), replyMessageId: $last?->getKey(),
                );
            }

            $response = $this->celia->greet($conversation, $this->contact($conversation), $locale);

            return $this->toResult($conversation, $response, $this->lastAdvisorMessage($conversation));
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

            if ($turn->externalMessageId === null) {
                return $this->runTurn($conversation, $turn);
            }

            // Idempotencia: el mismo mensaje externo (webhook reintentado) se procesa una vez.
            $key = 'advisor-turn:'.$conversation->getKey().':'.sha1($turn->externalMessageId);

            return Cache::lock($key, 60)->block(20, fn (): AdvisorTurnResult => $this->alreadyProcessed($conversation, $turn->externalMessageId)
                ?? $this->runTurn($conversation, $turn));
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

        $response = $this->celia->handle($conversation, $this->contact($conversation), $turn->text, $turn->locale, $turn->externalMessageId);

        return $this->toResult($conversation, $response, $this->lastAdvisorMessage($conversation));
    }

    private function alreadyProcessed(Conversation $conversation, string $externalMessageId): ?AdvisorTurnResult
    {
        $seen = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('sender_type', 'user')
            ->where('external_id', $externalMessageId)
            ->first();
        if ($seen === null) {
            return null;
        }

        $reply = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('sender_type', 'celia')
            ->where('id', '>', $seen->getKey())
            ->orderBy('id')
            ->first();

        return new AdvisorTurnResult(
            status: 'duplicate', reply: $reply?->content, intent: 'duplicate', handoff: $conversation->mode === 'human',
            conversationId: (int) $conversation->getKey(), replyMessageId: $reply?->getKey(),
            sources: $this->sourcesOf($reply),
        );
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

        $existing = Conversation::query()
            ->where('bot_id', $bot->getKey())
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();
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
            default => 'answered',
        };

        return new AdvisorTurnResult(
            status: $status,
            reply: isset($response['reply']) ? (string) $response['reply'] : null,
            intent: $action,
            handoff: false, // el asesor no traspasa por sí mismo: lo hace una persona (handOff)
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
