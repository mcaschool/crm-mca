<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

class FollowUpEvent extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $fillable = ['institution_id', 'follow_up_opportunity_id', 'follow_up_cycle_id', 'follow_up_action_id', 'actor_user_id', 'event_type', 'actor_type', 'reason_code', 'policy_status', 'policy_version', 'safe_data', 'dedupe_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['safe_data' => 'array', 'occurred_at' => 'datetime'];
    }
}
