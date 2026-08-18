<?php

declare(strict_types=1);

namespace Modules\Audit\Policies;

use App\Models\User;
use Modules\Audit\Models\AuditLog;

/**
 * Autorizacion de la AUDITORIA. Es SOLO LECTURA y SOLO para Administrador o
 * super-admin: Marketing y Admisiones no la ven ni en el menu ni por URL directa.
 * No hay create/update/delete: la auditoria es append-only (la escribe el sistema)
 * y su purga la hara el cron de retencion, nunca la UI.
 *
 * El aislamiento por institucion lo garantiza el scope global de AuditLog
 * (BelongsToInstitution): una consulta jamas ve registros de otra institucion.
 */
class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canViewAudit();
    }

    public function view(User $actor, AuditLog $log): bool
    {
        return $actor->canViewAudit();
    }
}
