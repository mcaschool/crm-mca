<?php

declare(strict_types=1);

namespace Modules\Crm\Policies;

use App\Models\User;
use Modules\Crm\Models\Contact;

/**
 * CRM de contactos: los tres roles ven la ficha y el historial. Acciones
 * destructivas (borrado): solo Admin. Aislamiento por el scope global.
 */
class ContactPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canWorkCrm();
    }

    public function view(User $actor, Contact $contact): bool
    {
        return $actor->canWorkCrm();
    }

    public function delete(User $actor, Contact $contact): bool
    {
        return $actor->canManageUsers();
    }
}
