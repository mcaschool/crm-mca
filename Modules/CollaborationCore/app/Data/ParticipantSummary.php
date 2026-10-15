<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\ParticipantRole;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Support\Guard;

final readonly class ParticipantSummary
{
    public function __construct(
        public int $userId,
        public ParticipantRole $role,
        public ParticipantState $state,
        public ?string $deviceId = null,
    ) {
        Guard::id($userId);
        if ($deviceId !== null) {
            Guard::token($deviceId);
        }
    }

}
