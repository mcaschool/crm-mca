<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Recordatorio programado de una actividad (uno por desfase × destinatario × canal).
 * La clave única impide duplicados; el estado pending→sending→sent|failed hace el envío
 * idempotente aunque el planificador se solape o se reintente.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $activity_id
 * @property int $offset_minutes
 * @property string $recipient_type advisor|guest
 * @property int $recipient_id
 * @property string $channel mail|internal
 * @property Carbon $due_at
 * @property string $status pending|sending|sent|failed|skipped
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class ActivityReminder extends Model
{
    use BelongsToInstitution;

    public const MAX_ATTEMPTS = 3;

    protected $table = 'crm_activity_reminders';

    protected $fillable = [
        'institution_id', 'activity_id', 'offset_minutes', 'recipient_type', 'recipient_id',
        'channel', 'due_at', 'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
