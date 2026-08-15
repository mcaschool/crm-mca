<?php

declare(strict_types=1);

namespace Modules\Identity\Policies;

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Autorizacion de la gestion de usuarios del panel.
 *
 * Dos ejes que se combinan SIEMPRE:
 *  1. Capacidad: solo Administrador o super-admin gestionan usuarios.
 *  2. Aislamiento: la accion se limita a la institucion ACTIVA del contexto.
 *     Un Admin normal tiene fijada su propia institucion (middleware); el
 *     super-admin tiene la que haya elegido con el cambiador. Asi, incluso el
 *     super-admin opera dentro de una institucion a la vez.
 *
 * El control es en el BACKEND, por accion y recurso. Ocultar un boton no basta.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->canManageUsers() && $this->inActiveInstitution($target);
    }

    public function create(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->canManageUsers() && $this->inActiveInstitution($target);
    }

    /**
     * Desactivar (baja logica). No se permite desactivarse a uno mismo, para
     * evitar que un admin se deje sin acceso por error.
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $actor->canManageUsers()
            && $this->inActiveInstitution($target)
            && $actor->getKey() !== $target->getKey();
    }

    /**
     * El objetivo pertenece a la institucion activa del contexto.
     */
    private function inActiveInstitution(User $target): bool
    {
        $active = app(CurrentInstitution::class)->id();

        return $active !== null && (int) $target->institution_id === $active;
    }
}
