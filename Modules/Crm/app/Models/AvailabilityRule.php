<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Intervalo semanal de disponibilidad de un asesor (hora local de su perfil).
 * Varios intervalos por día; un día sin intervalos = día deshabilitado.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property int $weekday 0 = domingo … 6 = sábado
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 */
class AvailabilityRule extends Model
{
    use BelongsToInstitution;

    protected $table = 'crm_availability_rules';

    protected $fillable = ['institution_id', 'user_id', 'weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
