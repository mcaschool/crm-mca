<?php

declare(strict_types=1);

namespace Modules\Identity\Listeners;

use Illuminate\Auth\Events\Failed;
use Modules\Audit\Services\AuditService;

/**
 * Registra en auditoria cada intento de login FALLIDO (credenciales incorrectas).
 * Solo el correo intentado + IP; nunca la contraseña.
 */
class LogFailedLogin
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $this->audit->logAuth('auth.login_failed', is_string($email) ? $email : null);
    }
}
