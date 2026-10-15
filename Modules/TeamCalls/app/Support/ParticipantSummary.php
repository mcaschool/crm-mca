<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Illuminate\Support\Carbon;
use Modules\TeamCalls\Models\CallParticipant;

/** Vista pública de un participante (sin datos personales: solo el id de usuario). */
final class ParticipantSummary
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly string $state,
        public readonly ?Carbon $invitedAt,
        public readonly ?Carbon $respondedAt,
        public readonly ?Carbon $joinedAt,
        public readonly ?Carbon $leftAt,
    ) {}

    public static function from(CallParticipant $p): self
    {
        return new self($p->user_id, $p->role->value, $p->state->value, $p->invited_at, $p->responded_at, $p->joined_at, $p->left_at);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->role,
            'state' => $this->state,
            'invited_at' => $this->invitedAt?->toIso8601String(),
            'responded_at' => $this->respondedAt?->toIso8601String(),
            'joined_at' => $this->joinedAt?->toIso8601String(),
            'left_at' => $this->leftAt?->toIso8601String(),
        ];
    }
}
