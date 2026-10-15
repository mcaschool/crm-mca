<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Data;

use Modules\CollaborationCore\Support\Guard;

/** Server-side metadata; never serialized into the public event envelope. */
final readonly class DeliveryAudience
{
    /** @param list<int> $userIds */
    public function __construct(
        public int $institutionId,
        public array $userIds,
        public ?string $exceptDeviceId = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
        Guard::id($institutionId);
        Guard::users($userIds, true);
        if ($exceptDeviceId !== null) {
            Guard::token($exceptDeviceId);
        }
    }
}
