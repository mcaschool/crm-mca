<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use App\Models\User;

/**
 * PUERTO hacia el directorio de usuarios y el permiso de colaboración (CollaborationCore:
 * UserDirectory + gate collab.use). Sobre la tabla users existente: no hay otra tabla de usuarios.
 */
interface UserDirectory
{
    /** ¿Puede usar las llamadas internas? (usuario activo y con acceso a la colaboración). */
    public function canUseCalls(User $user): bool;

    /** ¿Puede $from llamar o invitar a $to? (misma institución, activo y con acceso). */
    public function canContact(User $from, User $to): bool;
}
