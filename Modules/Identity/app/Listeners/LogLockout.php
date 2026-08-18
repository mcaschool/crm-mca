<?php

declare(strict_types=1);

namespace Modules\Identity\Listeners;

use Illuminate\Auth\Events\Lockout;
use Modules\Audit\Services\AuditService;

/**
 * Registra en auditoria cada BLOQUEO por fuerza bruta (se superaron los intentos
 * permitidos para la combinacion correo+IP).
 */
class LogLockout
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Lockout $event): void
    {
        $email = $event->request->input('email');
        $this->audit->logAuth('auth.lockout', is_string($email) ? $email : null);
    }
}
