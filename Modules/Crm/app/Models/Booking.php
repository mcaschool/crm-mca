<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Reserva pública: la actividad del Calendario creada por un visitante, con su token de gestión
 * (solo el hash) y el estado de los correos posteriores a la reserva.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $activity_id
 * @property int|null $meeting_type_id
 * @property string $token_hash
 * @property string|null $token
 * @property string $idempotency_key
 * @property string $visitor_name
 * @property string $visitor_email
 * @property string|null $visitor_phone
 * @property string $visitor_timezone
 * @property string $visitor_language
 * @property string|null $notes
 * @property int|null $booking_link_id
 * @property Carbon|null $confirmation_sent_at
 * @property Carbon|null $advisor_notified_at
 * @property Carbon|null $advisor_mailed_at
 * @property int $mail_attempts
 * @property string|null $mail_error
 * @property Carbon $created_at
 */
class Booking extends Model
{
    use BelongsToInstitution;

    public const MAX_MAIL_ATTEMPTS = 3;

    protected $table = 'crm_bookings';

    protected $fillable = [
        'institution_id', 'activity_id', 'meeting_type_id', 'token_hash', 'token', 'idempotency_key',
        'visitor_name', 'visitor_email', 'visitor_phone', 'visitor_timezone', 'visitor_language',
        'notes', 'booking_link_id', 'confirmation_sent_at', 'advisor_notified_at', 'advisor_mailed_at', 'mail_attempts', 'mail_error',
    ];

    protected $hidden = ['token_hash', 'token'];

    protected function casts(): array
    {
        return [
            'visitor_phone' => 'encrypted',
            'confirmation_sent_at' => 'datetime',
            'advisor_notified_at' => 'datetime',
            'advisor_mailed_at' => 'datetime',
            'token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<MeetingType, $this> */
    public function meetingType(): BelongsTo
    {
        return $this->belongsTo(MeetingType::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** ¿Admite todavía cancelar o reprogramar? (programada y sin empezar) */
    public function isManageable(): bool
    {
        $activity = $this->activity;

        return $activity !== null && $activity->isScheduled() && $activity->starts_at->isFuture();
    }
}
