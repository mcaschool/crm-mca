<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Illuminate\Support\Carbon;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;

/**
 * Vista pública de una llamada para otros módulos y futuros clientes (contrato CallSummary). Nunca
 * incluye credenciales de la sala ni la clave de idempotencia.
 */
final class CallSummary
{
    /** @param list<ParticipantSummary> $participants */
    public function __construct(
        public readonly string $id,
        public readonly int $institutionId,
        public readonly string $media,
        public readonly string $scope,
        public readonly string $status,
        public readonly ?ConversationRef $conversation,
        public readonly int $initiatorId,
        public readonly array $participants,
        public readonly Carbon $createdAt,
        public readonly ?Carbon $answeredAt,
        public readonly ?Carbon $endedAt,
        public readonly ?string $endReason,
        public readonly ?string $viewerState,
    ) {}

    public static function from(Call $call, ?int $viewerId = null): self
    {
        $participants = $call->participants->sortBy('id')->values()
            ->map(fn (CallParticipant $p) => ParticipantSummary::from($p))->all();

        return new self(
            $call->id,
            $call->institution_id,
            $call->media->value,
            $call->scope->value,
            $call->status->value,
            $call->context_type !== null && $call->context_id !== null ? new ConversationRef($call->context_type, $call->context_id) : null,
            $call->initiator_id,
            $participants,
            $call->created_at,
            $call->answered_at,
            $call->ended_at,
            $call->end_reason,
            $viewerId !== null ? $call->participantFor($viewerId)?->state->value : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institutionId,
            'media' => $this->media,
            'scope' => $this->scope,
            'status' => $this->status,
            'conversation' => $this->conversation !== null ? ['type' => $this->conversation->type, 'id' => $this->conversation->id] : null,
            'initiator_id' => $this->initiatorId,
            'participants' => array_map(fn (ParticipantSummary $p) => $p->toArray(), $this->participants),
            'created_at' => $this->createdAt->toIso8601String(),
            'answered_at' => $this->answeredAt?->toIso8601String(),
            'ended_at' => $this->endedAt?->toIso8601String(),
            'end_reason' => $this->endReason,
            'viewer_state' => $this->viewerState,
        ];
    }
}
