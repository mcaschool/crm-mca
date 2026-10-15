<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\TeamCalls\Enums\ParticipantRole;
use Modules\TeamCalls\Enums\ParticipantState;

/**
 * Estado INDIVIDUAL de una persona en una llamada (invitación y participación).
 *
 * @property int $id
 * @property int $institution_id
 * @property string $call_id
 * @property int $user_id
 * @property ParticipantRole $role
 * @property ParticipantState $state
 * @property int|null $invited_by
 * @property Carbon|null $invited_at
 * @property Carbon|null $ring_expires_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property string|null $device_id
 */
class CallParticipant extends Model
{
    use BelongsToInstitution;

    protected $table = 'collab_call_participants';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'state' => ParticipantState::class,
            'invited_at' => 'datetime',
            'ring_expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
