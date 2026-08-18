<?php

declare(strict_types=1);

namespace Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Audit\Database\Factories\AuditLogFactory;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Registro de auditoria append-only. Traza quien cambio que y cuando; critico
 * para las credenciales cifradas (Bloque 2). `changes` guarda el cambio con los
 * SECRETOS REDACTADOS: registra que cambio api_key, nunca su valor.
 */
class AuditLog extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /** Append-only: solo created_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'changes',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Actor del evento (null en eventos de invitado, p. ej. login fallido antes de
     * autenticarse; en esos casos el correo intentado vive en `changes`).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
