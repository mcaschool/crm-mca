<?php

declare(strict_types=1);

namespace Modules\Social\Policies;

use App\Models\User;
use Modules\Social\Models\SocialChannel;

/**
 * Autorización de la gestión de canales sociales (credenciales cifradas de WhatsApp /
 * Página FB / IG). Mismo patrón que IntegrationPolicy: SOLO Administrador o super-admin.
 * Marketing y Admisiones NO gestionan credenciales. El aislamiento por institución lo
 * garantiza el scope global de SocialChannel (BelongsToInstitution).
 */
class SocialChannelPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }

    public function view(User $actor, SocialChannel $channel): bool
    {
        return $actor->canManageIntegrations();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }

    public function update(User $actor, SocialChannel $channel): bool
    {
        return $actor->canManageIntegrations();
    }
}
