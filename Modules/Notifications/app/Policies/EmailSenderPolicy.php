<?php

declare(strict_types=1);

namespace Modules\Notifications\Policies;

use App\Models\User;
use Modules\Notifications\Models\EmailSender;

/**
 * Los remitentes de correo son parte de Ajustes: SOLO Administrador (o super-admin).
 * Mismo criterio que la marca/logo institucional (canManageSettings).
 */
class EmailSenderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageSettings();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageSettings();
    }

    public function update(User $actor, EmailSender $sender): bool
    {
        return $actor->canManageSettings();
    }

    public function delete(User $actor, EmailSender $sender): bool
    {
        return $actor->canManageSettings();
    }

    public function test(User $actor, EmailSender $sender): bool
    {
        return $actor->canManageSettings();
    }
}
