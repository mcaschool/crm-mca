<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Enums\CallScope;
use Modules\TeamCalls\Enums\CallStatus;

/**
 * Llamada interna (1:1 o grupal) con su estado GLOBAL. Aislada por institución.
 *
 * @property string $id ULID
 * @property int $institution_id
 * @property int $initiator_id
 * @property CallMedia $media
 * @property CallScope $scope
 * @property string|null $context_type direct | group | channel (conversación de origen)
 * @property string|null $context_id
 * @property CallStatus $status
 * @property string $room_name
 * @property string $idempotency_key
 * @property string $request_hash huella de destino + medio para detectar claves reutilizadas
 * @property Carbon|null $ringing_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Call extends Model
{
    use BelongsToInstitution;
    use HasUlids;

    protected $table = 'collab_call_calls';

    /** Nada asignable en masa: el servicio fija cada campo de forma explícita. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'media' => CallMedia::class,
            'scope' => CallScope::class,
            'status' => CallStatus::class,
            'ringing_at' => 'datetime',
            'answered_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return HasMany<CallParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class, 'call_id');
    }

    /** @return HasMany<CallEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class, 'call_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function participantFor(int $userId): ?CallParticipant
    {
        return $this->participants->firstWhere('user_id', $userId);
    }
}
