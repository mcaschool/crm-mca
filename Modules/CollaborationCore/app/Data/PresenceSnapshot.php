<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Support\Guard;

final readonly class PresenceSnapshot
{
    public function __construct(
        public int $institutionId,
        public int $userId,
        public ManualPresence $manualStatus,
        public bool $online,
        public ?\DateTimeImmutable $lastSeenAt,
        public ?string $callId,
    ) {
        Guard::id($institutionId);
        Guard::id($userId);
        if ($callId !== null) {
            Guard::token($callId);
        }
    }

}
