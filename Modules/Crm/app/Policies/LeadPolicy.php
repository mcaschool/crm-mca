<?php

declare(strict_types=1);

namespace Modules\Crm\Policies;

use App\Models\User;
use Modules\Crm\Models\Lead;
use Modules\Identity\Enums\UserRole;

/**
 * CRM de leads: los TRES roles VEN los prospectos, pero ACTUAR se gobierna por
 * persona (ver User::crmPersona), de forma consistente en todo el CRM:
 *  - Admin/super: actua sobre todo.
 *  - Admisiones y Academico: actuan sobre todo.
 *  - Marketing: SOLO LECTURA (nunca edita).
 *  - Soporte: solo lectura, EXCEPTO los leads referidos a el (assigned_to_user_id).
 * Acciones destructivas (delete): solo Admin. Aislamiento por el scope global.
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

    /**
     * Alta manual de lead: Admin, Admisiones y Academico. Marketing (solo lectura)
     * y Soporte (solo sus referidos) NO crean leads a mano.
     */
    public function create(User $actor): bool
    {
        if ($actor->isSuperAdmin() || $actor->role === UserRole::Admin) {
            return true;
        }
        if ($actor->role === UserRole::Admissions) {
            return $actor->department !== 'Soporte';
        }

        return false;
    }

    /** Cambiar estado/interes/notas/transferir: segun persona (ver arriba). */
    public function update(User $actor, Lead $lead): bool
    {
        if ($actor->isSuperAdmin() || $actor->role === UserRole::Admin) {
            return true;
        }

        if ($actor->role === UserRole::Marketing) {
            return false; // solo lectura
        }

        // Solo queda Admisiones. Soporte: solo sus propios referidos; el resto
        // (incl. Academico): actua sobre todo.
        if ($actor->department === 'Soporte') {
            return $lead->assigned_to_user_id === $actor->getKey();
        }

        return true;
    }

    /** Accion destructiva: solo Admin/super. */
    public function delete(User $actor, Lead $lead): bool
    {
        return $actor->canManageUsers();
    }
}
