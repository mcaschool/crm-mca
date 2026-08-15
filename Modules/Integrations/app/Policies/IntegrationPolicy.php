<?php

declare(strict_types=1);

namespace Modules\Integrations\Policies;

use App\Models\User;
use Modules\Integrations\Models\Integration;

/**
 * Autorizacion del almacen de credenciales. Solo Administrador o super-admin.
 * Marketing y Admisiones NO acceden a las integraciones ni a los secretos.
 *
 * El aislamiento por institucion lo garantiza el scope global de Integration
 * (BelongsToInstitution): una consulta jamas ve integraciones de otra institucion.
 */
class IntegrationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }

    public function view(User $actor, Integration $integration): bool
    {
        return $actor->canManageIntegrations();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }

    public function update(User $actor, Integration $integration): bool
    {
        return $actor->canManageIntegrations();
    }

    public function test(User $actor, Integration $integration): bool
    {
        return $actor->canManageIntegrations();
    }
}
