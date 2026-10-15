<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;

/** Validate authentication, session, institution and registered device on the server. Never hydrate context from request JSON. */
interface ActorContextFactory
{
    public function fromAuthenticatedRequest(\Illuminate\Http\Request $request): ActorContext;
}
