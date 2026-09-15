<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Marca de "visto" por usuario y módulo del CRM (watermark de los badges del menú:
 * leads | contacts). Una fila por (usuario, módulo); "nuevo" = created_at del
 * registro > last_seen_at. Acotada por institución (regla de esquema del proyecto).
 *
 * @property int $institution_id
 * @property int $user_id
 * @property string $module
 * @property \Illuminate\Support\Carbon $last_seen_at
 */
class CrmModuleRead extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'user_id',
        'module',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
