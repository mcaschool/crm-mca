<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;

final class FollowUpEventRecorder
{
    /** @param array<string,bool|int|string|null> $safeData */
    public function record(string $type, ?FollowUpOpportunity $opportunity = null, ?FollowUpAction $action = null, ?string $reason = null, array $safeData = [], ?string $dedupeKey = null): FollowUpEvent
    {
        $institutionId = $opportunity !== null ? $opportunity->institution_id : $action?->institution_id;
        $opportunityId = $opportunity !== null ? $opportunity->getKey() : $action?->follow_up_opportunity_id;

        return FollowUpEvent::query()->create([
            'institution_id' => $institutionId,
            'follow_up_opportunity_id' => $opportunityId,
            'follow_up_cycle_id' => $action?->follow_up_cycle_id,
            'follow_up_action_id' => $action?->getKey(),
            'actor_user_id' => auth()->id(),
            'event_type' => $type,
            'actor_type' => auth()->check() ? 'user' : 'system',
            'reason_code' => $reason,
            'policy_status' => $action?->policy_status,
            'policy_version' => $action?->policy_version,
            'safe_data' => $safeData === [] ? null : $safeData,
            'dedupe_key' => $dedupeKey,
            'occurred_at' => now(),
        ]);
    }
}
