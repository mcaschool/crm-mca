<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Enums\ActivityMedium;

/**
 * Tipo de reunión que un asesor ofrece en su página pública de reservas (nombre, duración,
 * medio fijo, instrucciones y programa opcional). La disponibilidad es la del asesor.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $duration_minutes
 * @property ActivityMedium $medium
 * @property bool $is_active
 * @property bool $is_public
 * @property string|null $instructions
 * @property int|null $program_id
 * @property int $sort
 */
class MeetingType extends Model
{
    use BelongsToInstitution;

    /** Medios que admite una reserva pública (todos con un dato de conexión del perfil). */
    public const PUBLIC_MEDIA = ['google_meet', 'zoom', 'whatsapp', 'phone', 'in_person'];

    protected $table = 'crm_meeting_types';

    protected $fillable = [
        'institution_id', 'user_id', 'name', 'slug', 'description', 'duration_minutes', 'medium',
        'is_active', 'is_public', 'instructions', 'program_id', 'sort',
    ];

    protected $attributes = [
        'duration_minutes' => 30,
        'is_active' => true,
        'is_public' => false,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'medium' => ActivityMedium::class,
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** ¿Visible y reservable públicamente ahora mismo? */
    public function isBookable(): bool
    {
        return $this->is_active && $this->is_public;
    }
}
