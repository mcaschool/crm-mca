<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\AuthenticatedSessionContext;

interface AuthenticatedSessionContextFactory
{
    /** Verify login, required MFA, active account and institution before enrollment. */
    public function fromAuthenticatedRequest(\Illuminate\Http\Request $request): AuthenticatedSessionContext;
}
