<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Excepción de fecha de la disponibilidad: ausencia/vacaciones (unavailable, todo el día o
 * una franja) o un horario extra en una fecha concreta (available). Fechas locales del asesor.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property Carbon $date_from
 * @property Carbon $date_to
 * @property string $kind unavailable|available
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $reason
 */
class AvailabilityException extends Model
{
    use BelongsToInstitution;

    protected $table = 'crm_availability_exceptions';

    protected $fillable = ['institution_id', 'user_id', 'date_from', 'date_to', 'kind', 'start_time', 'end_time', 'reason'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
        ];
    }

    public function isAllDay(): bool
    {
        return $this->start_time === null || $this->end_time === null;
    }
}
