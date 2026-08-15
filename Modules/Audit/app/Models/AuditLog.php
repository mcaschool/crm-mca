<?php

declare(strict_types=1);

namespace Modules\Audit\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
