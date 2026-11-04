<?php

declare(strict_types=1);

namespace Modules\FollowUp\Policies;

use App\Models\User;
use Modules\FollowUp\Models\FollowUpOpportunity;

/** Etapa 1: vista administrativa de solo lectura y minimo privilegio. */
class FollowUpOpportunityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageSettings();
    }

    public function view(User $actor, FollowUpOpportunity $opportunity): bool
    {
        return $actor->canManageSettings();
    }
}
