<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Storage;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\ManualPresence;

/** Internal persistence port; Redis adapters must preserve aggregation and tenant keys. */
interface PresenceStore
{
    public function heartbeat(ActorContext $actor): void;

    public function manual(ActorContext $actor, ManualPresence $status): void;

    public function snapshot(int $institutionId, int $userId): PresenceSnapshot;

    public function call(int $institutionId, int $userId, string $callId, ?int $ttlSeconds): void;
}
