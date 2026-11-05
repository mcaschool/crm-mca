<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property int $institution_id
 * @property int $follow_up_opportunity_id
 * @property int $follow_up_cycle_id
 * @property string $type
 * @property string $channel
 * @property string $purpose
 * @property string $status
 * @property string $policy_status
 * @property string $policy_reason_code
 * @property string $policy_version
 * @property int $eligibility_version
 * @property string $idempotency_key
 * @property bool $approval_required
 * @property int|null $approved_by_user_id
 * @property int|null $cancelled_by_user_id
 * @property int|null $social_whatsapp_template_id
 * @property string|null $block_reason
 * @property int $attempt_number
 * @property int $cycle_attempt_number
 * @property \Carbon\CarbonInterface|null $scheduled_at
 * @property \Carbon\CarbonInterface|null $expires_at
 * @property \Carbon\CarbonInterface|null $approved_at
 * @property \Carbon\CarbonInterface|null $cancelled_at
 * @property \Carbon\CarbonInterface|null $paused_at
 * @property FollowUpOpportunity $opportunity
 * @property string $draft_state
 * @property int $draft_version
 * @property array<string,mixed>|null $draft_payload
 * @property string|null $context_fingerprint
 * @property string|null $draft_reservation
 * @property \Carbon\CarbonInterface|null $draft_reserved_at
 * @property \Carbon\CarbonInterface|null $draft_generated_at
 * @property \Carbon\CarbonInterface|null $draft_edited_at
 * @property \Carbon\CarbonInterface|null $draft_expires_at
 * @property string|null $draft_error
 * @property int|null $social_message_id
 * @property string|null $execution_token
 * @property string|null $execution_error
 * @property int|null $approved_draft_version
 * @property string|null $approved_content_hash
 * @property \Carbon\CarbonInterface|null $approval_expires_at
 * @property \Carbon\CarbonInterface|null $claimed_at
 * @property \Carbon\CarbonInterface|null $dispatch_at
 * @property \Carbon\CarbonInterface|null $execution_queued_at
 * @property array<int,string>|null $execution_parameters
 */
class FollowUpAction extends Model
{
    use BelongsToInstitution;

    public const ACTIVE_STATUSES = ['draft', 'pending_approval', 'approved', 'scheduled', 'paused', 'claimed'];

    public const STATUSES = [...self::ACTIVE_STATUSES, 'sending', 'sent', 'delivered', 'read', 'failed', 'delivery_unknown', 'cancelled', 'expired', 'suppressed'];

    protected $hidden = ['draft_payload', 'draft_reservation'];

    protected $fillable = ['institution_id', 'follow_up_opportunity_id', 'follow_up_cycle_id', 'type', 'channel', 'purpose', 'status', 'scheduled_at', 'expires_at', 'policy_status', 'policy_reason_code', 'policy_version', 'eligibility_version', 'idempotency_key', 'approval_required', 'approved_by_user_id', 'approved_at', 'cancelled_by_user_id', 'cancelled_at', 'paused_at', 'block_reason', 'social_whatsapp_template_id', 'attempt_number', 'cycle_attempt_number'];

    protected function casts(): array
    {
        return array_merge($this->baseCasts(), ['execution_parameters' => 'encrypted:array', 'approved_draft_version' => 'integer', 'approval_expires_at' => 'datetime', 'claimed_at' => 'datetime', 'dispatch_at' => 'datetime', 'execution_queued_at' => 'datetime']);
    }

    /** @return array<string,string> */
    private function baseCasts(): array
    {
        return ['draft_payload' => 'encrypted:array', 'draft_version' => 'integer', 'draft_reserved_at' => 'datetime', 'draft_generated_at' => 'datetime', 'draft_expires_at' => 'datetime', 'draft_edited_at' => 'datetime', 'scheduled_at' => 'datetime', 'expires_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'paused_at' => 'datetime', 'approval_required' => 'boolean', 'eligibility_version' => 'integer', 'attempt_number' => 'integer', 'cycle_attempt_number' => 'integer'];
    }

    /** @return BelongsTo<FollowUpOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(FollowUpOpportunity::class, 'follow_up_opportunity_id');
    }

    /** @return BelongsTo<FollowUpCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(FollowUpCycle::class, 'follow_up_cycle_id');
    }
}
