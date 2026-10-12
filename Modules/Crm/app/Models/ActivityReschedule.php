<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Registro de una reprogramación (horas anteriores y nuevas, quién y por qué).
 *
 * @property int $institution_id
 * @property int $activity_id
 * @property Carbon $previous_starts_at
 * @property Carbon $previous_ends_at
 * @property Carbon $new_starts_at
 * @property Carbon $new_ends_at
 * @property int|null $by_user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class ActivityReschedule extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $table = 'crm_activity_reschedules';

    protected $fillable = [
        'institution_id', 'activity_id', 'previous_starts_at', 'previous_ends_at',
        'new_starts_at', 'new_ends_at', 'by_user_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_starts_at' => 'datetime',
            'previous_ends_at' => 'datetime',
            'new_starts_at' => 'datetime',
            'new_ends_at' => 'datetime',
        ];
    }
}
