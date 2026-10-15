<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class NotificationAudience
{
    /** @param list<int> $userIds */
    public function __construct(public int $institutionId, public array $userIds, public ?string $exceptDeviceId = null)
    {
        Guard::id($institutionId);
        Guard::users($userIds, true);
        if ($exceptDeviceId !== null) {
            Guard::token($exceptDeviceId);
        }
    }
}
