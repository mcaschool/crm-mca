<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;

interface NotificationDispatcher
{
    /** Durable idempotent enqueue, revalidated recipients and devices; no duplicate direct push path.
     * Caller supplies trusted institutional context; implementations reject audience mismatch.
     * Notification delivery may use Realtime through an adapter, not a Core dependency.
     */
    public function enqueue(InstitutionContext $context, NotificationIntent $intent, NotificationAudience $audience): string;
}
