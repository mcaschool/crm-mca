<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\CallScope;
use Modules\CollaborationCore\Enums\CallStatus;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Support\Guard;

final readonly class CallSummary
{
    /** @param list<ParticipantSummary> $participants */
    public function __construct(
        public string $id,
        public int $institutionId,
        public CallMedia $media,
        public CallScope $scope,
        public CallStatus $status,
        public ?ConversationRef $conversation,
        public int $initiatorId,
        public array $participants,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $answeredAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?string $endReason = null,
        public ?ParticipantState $viewerState = null,
    ) {
        Guard::token($id);
        Guard::id($institutionId);
        Guard::id($initiatorId);
        if (! array_is_list($participants)) {
            throw new \InvalidArgumentException('Expected a participant list.');
        }
        foreach ($participants as $participant) {
            if (! $participant instanceof ParticipantSummary) {
                throw new \InvalidArgumentException('Invalid participant.');
            }
        }
        Guard::users(array_map(static fn (ParticipantSummary $p): int => $p->userId, $participants), true);
    }

}
