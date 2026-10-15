<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\ManualPresence;

interface PresenceService
{
    public function heartbeat(ActorContext $context): PresenceSnapshot;

    public function setManualStatus(ActorContext $context, ManualPresence $status): PresenceSnapshot;

    /**
     * @param  list<int>  $userIds
     * @return list<PresenceSnapshot>
     */
    public function snapshot(ActorContext $context, array $userIds): array;

    /** Internal TeamCalls adapter only; validate institution/user/call association. Apply after commit. */
    public function setInCall(InstitutionContext $context, int $userId, string $callId, int $ttlSeconds): void;

    public function clearInCall(InstitutionContext $context, int $userId, string $callId): void;
}
