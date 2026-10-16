<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Events;

final readonly class ConversationMembershipRevoked extends RevocationNotice
{
    /** scope is direct/group/channel/team; team revocations also emit each affected channel. */
    public function __construct(int $institutionId, int $userId, string $scope, string $resourceId, string $reason)
    {
        parent::__construct($institutionId, $userId, $scope, $resourceId, $reason);
    }

    public function type(): string
    {
        return 'conversation.membership.revoked';
    }
}
