<?php

declare(strict_types=1);

namespace Modules\Crm\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Crm\Models\Activity;

/**
 * Agenda, gobernada por persona (User::crmPersona), sin nombres de usuario ni de
 * departamento en el código:
 *  - Administrador: todas las actividades de su institución; asigna a otros y edita
 *    perfiles/disponibilidad ajenos.
 *  - Usuario operativo (asesor): sus propias actividades (asignadas o creadas por él).
 *  - Solo lectura (persona crmReadOnly): ve la agenda, no modifica nada.
 * El aislamiento entre instituciones lo garantiza el scope global del modelo.
 */
class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canWorkCrm();
    }

    public function view(User $actor, Activity $activity): bool
    {
        if (! $actor->canWorkCrm()) {
            return false;
        }
        if ($this->seesAll($actor)) {
            return true;
        }

        return $activity->assigned_to_user_id === $actor->id || $activity->created_by_user_id === $actor->id;
    }

    public function create(User $actor): bool
    {
        return $actor->canWorkCrm() && ! $actor->crmReadOnly();
    }

    public function update(User $actor, Activity $activity): bool
    {
        if (! $actor->canWorkCrm() || $actor->crmReadOnly()) {
            return false;
        }

        return $actor->canManageCrm() || $activity->assigned_to_user_id === $actor->id;
    }

    /** Asignar actividades a otra persona y filtrar por asesor: solo administración. */
    public function manageOthers(User $actor): bool
    {
        return $actor->canWorkCrm() && $actor->canManageCrm();
    }

    /** ¿Ve la agenda completa de la institución? Admin y perfiles de solo lectura. */
    public function seesAll(User $actor): bool
    {
        return $actor->canWorkCrm() && ($actor->canManageCrm() || $actor->crmReadOnly());
    }

    /**
     * Limita una consulta a lo que el usuario puede ver.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public static function visibleQuery(Builder $query, User $actor): Builder
    {
        if (! $actor->canWorkCrm()) {
            return $query->whereRaw('1 = 0');
        }
        if ((new self)->seesAll($actor)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('assigned_to_user_id', $actor->id)->orWhere('created_by_user_id', $actor->id));
    }
}
