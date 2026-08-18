<?php

declare(strict_types=1);

namespace Modules\Audit\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Audit\Models\AuditLog;
use Modules\Institutions\Models\Institution;

/**
 * Registro de auditoria append-only: quien hizo que y cuando. `changes` guarda el
 * cambio con los VALORES SENSIBLES REDACTADOS (registra que se accedio/cambio,
 * nunca el dato personal ni el secreto en claro). institution_id se sella solo
 * por el trait BelongsToInstitution.
 */
class AuditService
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string,mixed>  $changes  metadatos NO sensibles (p. ej. el campo accedido)
     */
    public function log(string $action, Model $auditable, array $changes = []): AuditLog
    {
        // Se usa create() (asignacion masiva) a proposito: `changes` colisiona con
        // la propiedad protegida interna de Eloquent si se asigna por atributo.
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => (int) $auditable->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'ip' => $this->request->ip(),
        ]);
    }

    /**
     * Auditoria de eventos de AUTENTICACION (contexto guest: aun no hay usuario ni
     * institucion activa). Resuelve la institucion desde el correo intentado (si el
     * usuario existe) o, en su defecto, la unica de la instalacion. El correo NO es
     * secreto y es imprescindible para investigar; NUNCA se registra la contraseña
     * ni el TOTP. Devuelve null si no se puede determinar institucion (no rompe login).
     *
     * @param  array<string,mixed>  $changes
     */
    public function logAuth(string $action, ?string $email, array $changes = []): ?AuditLog
    {
        $normalized = $email !== null ? mb_strtolower(trim($email)) : null;

        $user = $normalized !== null && $normalized !== ''
            ? User::query()->where('email', $normalized)->first()
            : null;

        // institution_id explicito -> el trait BelongsToInstitution no lo sobrescribe.
        $institutionId = ($user ? $user->institution_id : null) ?? Institution::query()->orderBy('id')->value('id');
        if ($institutionId === null) {
            return null;
        }

        return AuditLog::create([
            'institution_id' => $institutionId,
            'user_id' => $user?->getKey(),
            'action' => $action,
            'auditable_type' => (new User)->getMorphClass(),
            'auditable_id' => (int) ($user?->getKey() ?? 0),
            'changes' => array_merge($normalized ? ['email' => $normalized] : [], $changes) ?: null,
            'ip' => $this->request->ip(),
        ]);
    }
}
