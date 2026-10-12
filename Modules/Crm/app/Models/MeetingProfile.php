<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Enums\ActivityMedium;

/**
 * Perfil de reuniones de un asesor (por institución y usuario). Guarda SOLO enlaces de
 * participante (nunca de anfitrión/administración), cifrados, igual que los números.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property string $timezone
 * @property ActivityMedium $default_medium
 * @property string|null $meet_url
 * @property string|null $zoom_url
 * @property string|null $whatsapp_number
 * @property string|null $phone
 * @property string|null $address
 * @property bool $is_active
 * @property bool $booking_enabled
 * @property string|null $booking_slug
 * @property int $default_duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $min_notice_minutes
 * @property int $max_horizon_days
 */
class MeetingProfile extends Model
{
    use BelongsToInstitution;

    protected $table = 'crm_meeting_profiles';

    protected $fillable = [
        'institution_id', 'user_id', 'timezone', 'default_medium', 'meet_url', 'zoom_url',
        'whatsapp_number', 'phone', 'address', 'is_active', 'booking_enabled', 'booking_slug', 'default_duration_minutes',
        'buffer_before_minutes', 'buffer_after_minutes', 'min_notice_minutes', 'max_horizon_days',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
        'default_medium' => 'google_meet',
        'is_active' => true,
        'booking_enabled' => false,
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_horizon_days' => 90,
    ];

    protected function casts(): array
    {
        return [
            'default_medium' => ActivityMedium::class,
            'meet_url' => 'encrypted',
            'zoom_url' => 'encrypted',
            'whatsapp_number' => 'encrypted',
            'phone' => 'encrypted',
            'is_active' => 'boolean',
            'booking_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Perfil del usuario en la institución activa, o uno nuevo (sin guardar) con valores por defecto. */
    public static function forUser(int $userId): self
    {
        return self::query()->where('user_id', $userId)->first()
            ?? new self(['user_id' => $userId, 'timezone' => (string) config('crm.agenda.default_timezone', 'UTC')]);
    }

    /** Enlace a copiar en una actividad nueva según el medio (foto en el momento de crear). */
    public function linkFor(ActivityMedium $medium): ?string
    {
        if (! $this->is_active) {
            return null;
        }

        return match ($medium) {
            ActivityMedium::GoogleMeet => $this->meet_url ?: null,
            ActivityMedium::Zoom => $this->zoom_url ?: null,
            default => null,
        };
    }
}
