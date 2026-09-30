<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Program;

/**
 * Gestion del catalogo (programas, categorias de formacion, areas): SOLO Administrador
 * (o super-admin). Ningun otro rol accede. El aislamiento por institucion lo da el scope
 * global de Program.
 */
class ProgramPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageCatalog();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageCatalog();
    }

    public function update(User $actor, Program $program): bool
    {
        return $actor->canManageCatalog();
    }

    /** Archivar (borrado suave), restaurar y eliminar definitivo: solo Admin. */
    public function delete(User $actor, Program $program): bool
    {
        return $actor->canManageCatalog();
    }
}
