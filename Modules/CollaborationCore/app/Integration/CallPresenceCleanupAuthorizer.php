<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Integration;

use Modules\CollaborationCore\Data\InstitutionContext;

/** Internal TeamCalls adapter only. Verify historical tenant/user/call association, including ended/left calls.
 * Bind in TeamCalls provider boot. Never expose this port through HTTP or use client claims as evidence.
 */
interface CallPresenceCleanupAuthorizer extends CallPresenceAuthorizer
{
    public function authorizeCleanup(InstitutionContext $context, int $userId, string $callId): void;
}
