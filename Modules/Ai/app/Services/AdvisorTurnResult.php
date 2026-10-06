<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Resultado de un turno (AdvisorTurnService) para el adaptador del canal.
 *
 * status:
 *  - answered       el asesor respondió (con IA o plantilla).
 *  - unresolved     no tenía información suficiente: lo dice con honestidad (no inventa).
 *  - limit_reached  se alcanzó el límite de mensajes de IA de la conversación.
 *  - unavailable    sin proveedor o el proveedor falló: respuesta honesta sin IA.
 *  - handoff        el asesor transfiere a una persona (sus reglas): su mensaje se envía y la
 *                   conversación pasa a «Esperando a una persona».
 *  - duplicate      el mensaje externo ya se procesó: NO se vuelve a enviar nada.
 *  - human_active   la conversación está traspasada a una persona: el bot NO responde.
 *
 * `intent` es la clasificación que ya produce el asesor (answer, unresolved, start_matcher…).
 * `sources` son códigos internos de las fuentes de conocimiento usadas (trazabilidad; el
 * adaptador no debe enviarlos al usuario). `adapter` lleva solo datos seguros para el canal.
 */
final readonly class AdvisorTurnResult
{
    /**
     * @param  array<int, string>  $sources
     * @param  array<string, mixed>  $adapter
     */
    public function __construct(
        public string $status,
        public ?string $reply,
        public string $intent,
        public bool $handoff,
        public int $conversationId,
        public ?int $replyMessageId = null,
        public array $sources = [],
        public bool $usedAi = false,
        public array $adapter = [],
    ) {}

    /** ¿Hay algo NUEVO que enviar al usuario por el canal? */
    public function shouldReply(): bool
    {
        return $this->reply !== null && $this->reply !== ''
            && ! in_array($this->status, ['duplicate', 'human_active'], true);
    }
}
