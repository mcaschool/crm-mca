<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Events;

final readonly class CollaborationAccessRevoked extends RevocationNotice
{
    public function __construct(int $institutionId, int $userId, string $reason)
    {
        parent::__construct($institutionId, $userId, 'user', (string) $userId, $reason);
    }

    public function type(): string
    {
        return 'collaboration.access.revoked';
    }
}
