<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Program;

/**
 * Gestion del catalogo: Administrador y Marketing. Admisiones NO tiene acceso.
 * El aislamiento por institucion lo da el scope global de Program.
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
}
