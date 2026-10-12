<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Sala institucional permanente de un asesor (Google Meet o Zoom). Solo se guarda la URL de
 * PARTICIPANTE (cifrada) y el identificador remoto; nunca enlaces de inicio ni claves de anfitrión.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $user_id
 * @property string $provider google_meet|zoom
 * @property string $remote_id
 * @property string $join_url
 * @property string $join_url_hash
 * @property string|null $host_key
 * @property string $status active|replaced|missing|error
 * @property string|null $host_status
 * @property string|null $host_note
 * @property int|null $created_by_user_id
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property Carbon $created_at
 */
class VideoRoom extends Model
{
    use BelongsToInstitution;

    public const PROVIDERS = ['google_meet', 'zoom'];

    protected $table = 'crm_video_rooms';

    protected $fillable = [
        'institution_id', 'user_id', 'provider', 'remote_id', 'join_url', 'join_url_hash', 'host_key', 'status',
        'host_status', 'host_note', 'created_by_user_id', 'last_checked_at', 'last_error',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'join_url' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** ¿El asesor puede iniciar y dirigir la reunión por sí mismo (confirmado por el proveedor)? */
    public function advisorCanHost(): bool
    {
        return in_array($this->host_status, ['assigned', 'advisor_host'], true);
    }
}
