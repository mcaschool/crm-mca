<?php

declare(strict_types=1);

namespace Modules\Ai\Policies;

use App\Models\User;
use Modules\Ai\Models\KnowledgeSource;

/**
 * Autorizacion de la base de conocimiento de Celia. La sincronizacion reprocesa
 * archivos del servidor y afecta lo que Celia responde: solo Administrador (o
 * super-admin), igual que las integraciones. El aislamiento por institucion lo da
 * el scope global de KnowledgeSource.
 */
class KnowledgeSourcePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }

    public function view(User $actor, KnowledgeSource $source): bool
    {
        return $actor->canManageIntegrations();
    }

    public function sync(User $actor): bool
    {
        return $actor->canManageIntegrations();
    }
}
