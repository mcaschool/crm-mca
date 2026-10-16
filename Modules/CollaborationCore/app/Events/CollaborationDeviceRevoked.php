<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Events;

final readonly class CollaborationDeviceRevoked extends RevocationNotice
{
    public function __construct(int $institutionId, int $userId, public string $deviceId, string $reason)
    {
        parent::__construct($institutionId, $userId, 'device', $deviceId, $reason);
    }

    public function type(): string
    {
        return 'collaboration.device.revoked';
    }
}
