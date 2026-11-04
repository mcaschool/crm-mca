<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\FollowUp\Models\FollowUpAction;

final class ActionInvalidator
{
    public function __construct(private readonly FollowUpEventRecorder $events) {}

    /** @param Builder<FollowUpAction> $query */
    private function cancel(Builder $query, string $reason): int
    {
        return DB::transaction(function () use ($query, $reason): int {
            $actions = $query->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->lockForUpdate()->get();
            foreach ($actions as $action) {
                app(FollowUpDraftService::class)->invalidate($action, $reason);
                $action->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => auth()->id(), 'block_reason' => $reason, 'eligibility_version' => $action->eligibility_version + 1])->save();
                $this->events->record('action.cancelled', action: $action, reason: $reason);
            }

            return $actions->count();
        }, 3);
    }

    public function forOpportunity(int $opportunityId, string $reason): int
    {
        return $this->cancel(FollowUpAction::query()->where('follow_up_opportunity_id', $opportunityId), $reason);
    }

    public function forConversation(int $conversationId, string $reason): int
    {
        return $this->cancel(FollowUpAction::query()->whereHas('opportunity', fn ($q) => $q->where('social_conversation_id', $conversationId)), $reason);
    }

    public function forInstitution(string $reason): int
    {
        return $this->cancel(FollowUpAction::query(), $reason);
    }

    public function forSubject(string $subjectKey, string $channel, string $purpose, string $reason): int
    {
        $query = FollowUpAction::query()->where('channel', $channel)->where('purpose', $purpose);
        if (str_starts_with($subjectKey, 'contact:')) {
            $query->whereHas('opportunity', fn ($q) => $q->where('contact_id', (int) substr($subjectKey, 8)));
        } else {
            $query->whereHas('opportunity', fn ($q) => $q->where('social_conversation_id', (int) substr($subjectKey, 13)));
        }

        return $this->cancel($query, $reason);
    }
}
