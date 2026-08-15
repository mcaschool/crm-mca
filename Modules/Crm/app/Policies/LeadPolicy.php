<?php

declare(strict_types=1);

namespace Modules\Crm\Policies;

use App\Models\User;
use Modules\Crm\Models\Lead;

/**
 * CRM de leads: los TRES roles (Admin, Marketing, Admisiones) ven y trabajan los
 * prospectos. Acciones destructivas: solo Admin. Aislamiento por el scope global.
 */
class LeadPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canWorkCrm();
    }

    public function view(User $actor, Lead $lead): bool
    {
        return $actor->canWorkCrm();
    }

    /** Cambiar estado/interes/notas: cualquier rol del CRM. */
    public function update(User $actor, Lead $lead): bool
    {
        return $actor->canWorkCrm();
    }

    /** Accion destructiva: solo Admin/super. */
    public function delete(User $actor, Lead $lead): bool
    {
        return $actor->canManageUsers();
    }
}
