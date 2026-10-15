<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

/** Notification intent contains minimal identifiers, never call credentials or message bodies. */
final readonly class NotificationIntent
{
    public function __construct(
        public string $id,
        public string $type,
        public string $resourceId,
        public string $sourceEventId,
        public string $deduplicationKey,
        public \DateTimeImmutable $expiresAt,
    ) {
        foreach ([$id, $type, $resourceId, $sourceEventId, $deduplicationKey] as $value) {
            Guard::token($value);
        }
    }
}
