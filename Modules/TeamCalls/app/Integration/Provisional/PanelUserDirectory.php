<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use App\Models\User;
use Modules\TeamCalls\Contracts\UserDirectory;

/**
 * PROVISIONAL hasta el gate collab.use y el UserDirectory de CollaborationCore. Usa SOLO lo que ya
 * existe en el CRM (tabla users y sus métodos de permiso), sin reglas nuevas: usuario activo que
 * trabaja el CRM (Administración, Marketing o Admisiones) y de la misma institución.
 */
final class PanelUserDirectory implements UserDirectory
{
    public function canUseCalls(User $user): bool
    {
        return $user->isActive() && $user->canWorkCrm() && $user->institution_id !== null;
    }

    public function canContact(User $from, User $to): bool
    {
        return (int) $from->id !== (int) $to->id
            && (int) $from->institution_id === (int) $to->institution_id
            && $this->canUseCalls($from)
            && $this->canUseCalls($to);
    }
}
