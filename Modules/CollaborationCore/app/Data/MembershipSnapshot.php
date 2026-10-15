<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class MembershipSnapshot
{
    public function __construct(
        public int $institutionId,
        public ConversationRef $conversation,
        public int $userId,
        public bool $active,
        public string $accessVersion,
    ) {
        Guard::id($institutionId);
        Guard::id($userId);
        Guard::token($accessVersion);
    }

}
