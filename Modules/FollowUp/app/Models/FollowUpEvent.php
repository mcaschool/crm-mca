<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property string $event_type
 * @property int|null $follow_up_action_id
 * @property \Carbon\CarbonInterface $occurred_at
 */
class FollowUpEvent extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FollowUpOpportunity, $this> */
    public function opportunity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FollowUpOpportunity::class, 'follow_up_opportunity_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id')->where('users.institution_id', app(\Modules\Core\Tenancy\CurrentInstitution::class)->idOrFail());
    }

    protected $fillable = ['institution_id', 'follow_up_opportunity_id', 'follow_up_cycle_id', 'follow_up_action_id', 'actor_user_id', 'event_type', 'actor_type', 'reason_code', 'policy_status', 'policy_version', 'safe_data', 'dedupe_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['safe_data' => 'array', 'occurred_at' => 'datetime'];
    }
}
