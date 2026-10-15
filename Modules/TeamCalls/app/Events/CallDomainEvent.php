<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento interno de Laravel emitido DESPUÉS de confirmar cada cambio de una llamada. Otros módulos
 * (p. ej. TeamChat para sus mensajes de sistema «llamada perdida») lo escuchan sin depender de las
 * clases internas de TeamCalls. Solo lleva ids y metadatos mínimos.
 */
abstract class CallDomainEvent
{
    use Dispatchable;

    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $callId,
        public readonly int $institutionId,
        public readonly ?int $actorId,
        public readonly ?int $subjectId,
        public readonly array $data,
        public readonly string $eventId,
    ) {}

    /** Tipo en la nomenclatura acordada (call.*). */
    abstract public static function type(): string;
}
