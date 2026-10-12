<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Enums\ActivityType;

/**
 * Actividad de la agenda (llamada, reunión, seguimiento, tarea…). Entidad propia,
 * independiente de `events` (que sigue siendo append-only). Fechas en UTC.
 *
 * @property int $id
 * @property int $institution_id
 * @property int|null $lead_id
 * @property int|null $contact_id
 * @property int $assigned_to_user_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property ActivityType $type
 * @property ActivityMedium $medium
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property string $priority
 * @property ActivityStatus $status
 * @property string|null $description
 * @property string|null $outcome
 * @property string|null $location
 * @property string|null $meeting_link
 * @property string|null $meeting_room_hash
 * @property array<int,int>|null $reminders
 * @property string $uid
 * @property int $ics_sequence
 * @property int $reschedule_count
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $exception_by_user_id
 * @property Carbon|null $exception_at
 * @property string|null $exception_reason
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Activity extends Model
{
    use BelongsToInstitution;

    public const PRIORITIES = ['low', 'normal', 'high'];

    /** Recordatorios disponibles (minutos antes). */
    public const REMINDER_OPTIONS = [1440, 60, 15];

    protected $table = 'crm_activities';

    protected $fillable = [
        'institution_id', 'lead_id', 'contact_id', 'assigned_to_user_id', 'created_by_user_id',
        'title', 'type', 'medium', 'starts_at', 'ends_at', 'timezone', 'priority', 'status',
        'description', 'outcome', 'location', 'meeting_link', 'meeting_room_hash', 'reminders', 'uid',
        'ics_sequence', 'reschedule_count', 'completed_at', 'cancelled_at',
        'exception_by_user_id', 'exception_at', 'exception_reason', 'idempotency_key',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'scheduled',
        'medium' => 'none',
        'ics_sequence' => 0,
        'reschedule_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'medium' => ActivityMedium::class,
            'status' => ActivityStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'exception_at' => 'datetime',
            'meeting_link' => 'encrypted',
            'reminders' => 'array',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function exceptionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_by_user_id');
    }

    /** @return HasMany<ActivityGuest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(ActivityGuest::class);
    }

    /** @return HasMany<ActivityGuest, $this> */
    public function activeGuests(): HasMany
    {
        return $this->hasMany(ActivityGuest::class)->whereNull('removed_at');
    }

    /** @return HasMany<ActivityReschedule, $this> */
    public function reschedules(): HasMany
    {
        return $this->hasMany(ActivityReschedule::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<Booking, $this> */
    public function booking(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Booking::class);
    }

    /** sha256 del enlace de sala (Meet/Zoom) normalizado, para detectar salas simultáneas. */
    public static function roomHash(?string $link): ?string
    {
        $link = is_string($link) ? strtolower(trim($link)) : '';
        if ($link === '' || ! str_starts_with($link, 'https://')) {
            return null;
        }

        return hash('sha256', rtrim(strtok($link, '#') ?: $link, '/'));
    }

    /** @return HasMany<ActivityReminder, $this> */
    public function reminderRows(): HasMany
    {
        return $this->hasMany(ActivityReminder::class);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', ActivityStatus::Scheduled->value);
    }

    public function isScheduled(): bool
    {
        return $this->status === ActivityStatus::Scheduled;
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Hora de inicio en la zona de la actividad. */
    public function localStart(?string $tz = null): Carbon
    {
        return $this->starts_at->copy()->setTimezone($tz ?? $this->timezone);
    }

    public function localEnd(?string $tz = null): Carbon
    {
        return $this->ends_at->copy()->setTimezone($tz ?? $this->timezone);
    }

    /** Nombre del lead/contacto vinculado (o null). */
    public function relatedName(): ?string
    {
        $contact = $this->contact ?? $this->lead?->contact;

        return $contact?->fullName();
    }

    /** Enlace para unirse/abrir (Meet, Zoom o wa.me). Null si no aplica. */
    public function joinUrl(): ?string
    {
        if ($this->medium === ActivityMedium::WhatsApp) {
            return app(\Modules\Crm\Services\Agenda\WhatsappLink::class)->forActivity($this);
        }
        if (in_array($this->medium, [ActivityMedium::GoogleMeet, ActivityMedium::Zoom], true)) {
            return $this->meeting_link ?: null;
        }

        return null;
    }
}
