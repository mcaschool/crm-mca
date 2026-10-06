<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Un turno conversacional que un ADAPTADOR de canal (Web Chat, modo de prueba y, en la siguiente
 * etapa, Instagram, Messenger y WhatsApp) entrega a AdvisorTurnService. Contrato común: el
 * adaptador traduce su payload a esto y aplica la respuesta en su canal; la lógica de
 * conocimiento y respuesta es una sola (CeliaService).
 *
 * - externalConversationId: id de la conversación en el canal de origen (sesión de prueba, hilo
 *   de Messenger/Instagram, número de WhatsApp…). Memoria separada por asesor + canal + este id.
 * - externalMessageId: id del mensaje en el canal (mid, wamid…). Idempotencia: el mismo id no
 *   se procesa dos veces (los webhooks se reintentan).
 * - attachments/metadata: contexto del canal; se aceptan pero hoy el asesor solo usa el texto.
 * - retryOnAiFailure: si la IA falla, lanzar AdvisorAiUnavailable (el canal reintenta) en vez de
 *   contestar «no disponible». Lo usan los canales sociales con despacho persistente.
 */
final readonly class AdvisorTurn
{
    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $institutionId,
        public int $botId,
        public string $channel,
        public string $externalConversationId,
        public string $text,
        public ?string $externalMessageId = null,
        public ?int $contactId = null,
        public string $locale = 'es',
        public bool $isTest = false,
        public array $attachments = [],
        public array $metadata = [],
        public bool $retryOnAiFailure = false,
    ) {}
}
