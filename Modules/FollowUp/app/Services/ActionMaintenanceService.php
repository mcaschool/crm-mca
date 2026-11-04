<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;

final class ActionMaintenanceService
{
    public function __construct(private readonly ActionInvalidator $invalidator, private readonly FollowUpEventRecorder $events, private readonly FollowUpPolicyEngine $policy) {}

    /** @return array{expired:int,invalidated:int} */
    public function run(): array
    {
        FollowUpAction::query()->whereIn('draft_state', ['queued', 'generating', 'review', 'no_contact'])->chunkById(100, function ($actions): void {
            foreach ($actions as $action) {
                app(FollowUpDraftService::class)->refreshValidity($action);
            }
        });
        $expired = 0;
        FollowUpAction::query()->with('opportunity')->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->where('expires_at', '<=', now())->chunkById(100, function ($actions) use (&$expired): void {
            foreach ($actions as $action) {
                $changed = FollowUpAction::query()->whereKey($action->id)->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->update(['status' => 'expired', 'block_reason' => 'proposal_expired']);
                if (! $changed) {
                    continue;
                }
                $this->events->record('action.expired', $action->opportunity, $action, 'proposal_expired');
                $expired++;
            }
        });
        $invalidated = 0;
        $conversationIds = FollowUpAction::query()->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->whereHas('opportunity.socialConversation', fn ($q) => $q->where('automation_state', '!=', 'bot')->orWhereNotNull('assigned_to'))->with('opportunity:id,social_conversation_id')->get()->pluck('opportunity.social_conversation_id')->filter()->unique();
        foreach ($conversationIds as $id) {
            $invalidated += $this->invalidator->forConversation((int) $id, 'human_intervention');
        }

        FollowUpAction::query()->with('opportunity')->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->chunkById(100, function ($actions) use (&$invalidated): void {
            foreach ($actions as $action) {
                $decision = $this->policy->evaluate(new PolicyRequest(
                    $action->opportunity, 'schedule', $action->channel, $action->purpose,
                    $action->scheduled_at, $action->expires_at, $action->social_whatsapp_template_id,
                    action: $action,
                ));
                if (! $decision->allows()) {
                    $invalidated += $this->invalidator->forOpportunity((int) $action->follow_up_opportunity_id, 'reevaluation_'.$decision->reasonCode);
                }
            }
        });

        return compact('expired', 'invalidated');
    }
}
