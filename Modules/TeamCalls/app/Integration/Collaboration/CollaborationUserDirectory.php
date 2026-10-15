<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\CollaborationCore\Authorization\Capabilities;
use Modules\TeamCalls\Contracts\UserDirectory;

/**
 * Directorio de personas con el permiso DEFINITIVO de MCA Colaboración (collaboration.use), que no exige
 * ni concede acceso al CRM. Preparado, NO activo: solo con teamcalls.authorization.driver = collaboration.
 * Falla cerrado mientras CollaborationCore no defina la habilidad (nadie puede usar llamadas).
 */
final class CollaborationUserDirectory implements UserDirectory
{
    public function canUseCalls(User $user): bool
    {
        return Gate::has(Capabilities::USE)
            && $user->isActive() && $user->institution_id !== null
            && Gate::forUser($user)->allows(Capabilities::USE);
    }

    public function canContact(User $from, User $to): bool
    {
        return (int) $from->id !== (int) $to->id
            && (int) $from->institution_id === (int) $to->institution_id
            && $this->canUseCalls($to);
    }
}
