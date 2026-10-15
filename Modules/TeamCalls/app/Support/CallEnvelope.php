<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Illuminate\Support\Carbon;

/**
 * Evento de llamada en el formato acordado para tiempo real, bandeja de eventos y push:
 * id, type, v, occurred_at, institution_id, actor_id y data. $recipients son los usuarios que deben
 * recibirlo; $exceptDeviceId excluye al dispositivo que lo provocó (p. ej. call.answered_elsewhere
 * no va al dispositivo que contestó).
 */
final class CallEnvelope
{
    /**
     * @param  array<string,mixed>  $data
     * @param  list<int>  $recipients
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $callId,
        public readonly int $institutionId,
        public readonly ?int $actorId,
        public readonly array $data,
        public readonly array $recipients,
        public readonly Carbon $occurredAt,
        public readonly ?string $exceptDeviceId = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'v' => 1,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'institution_id' => $this->institutionId,
            'actor_id' => $this->actorId,
            'data' => ['call_id' => $this->callId] + $this->data,
        ];
    }
}
