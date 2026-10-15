<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Integration;

use Modules\CollaborationCore\Data\InstitutionContext;

/** TeamCalls adapter must verify call tenant and participant; no HTTP/client binding. */
interface CallPresenceAuthorizer
{
    public function authorize(InstitutionContext $context, int $userId, string $callId): void;
}
