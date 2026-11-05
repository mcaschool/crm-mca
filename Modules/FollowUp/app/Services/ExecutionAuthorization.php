<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialMessage;

final class ExecutionAuthorization
{
    public function reason(?FollowUpAction $action, EffectiveFollowUpSettings $settings): ?string
    {
        if ($action === null || ! in_array($action->status, ['scheduled', 'claimed'], true) || $action->dispatch_at !== null || $action->scheduled_at === null || $action->scheduled_at->isFuture()) {
            return 'action_not_dispatchable';
        }
        $op = $action->opportunity;
        if (! \Modules\Institutions\Models\Institution::query()->whereKey($action->institution_id)->where('status', 'active')->exists()) {
            return 'institution_inactive';
        }
        $conversation = $op->socialConversation;
        $channel = $op->channel;
        $bot = Bot::query()->find($op->bot_id);
        if ($bot?->status !== 'active' || ! $channel?->advisor_enabled || (int) $channel->advisor_bot_id !== (int) $bot->id) {
            return 'advisor_changed_or_disabled';
        }
        if ($conversation === null || (int) $conversation->social_channel_id !== (int) $channel->id || $conversation->provider !== $action->channel
            || trim((string) ($conversation->contact_external_id ?? $conversation->external_conversation_id)) === '') {
            return 'recipient_unverified';
        }
        if (! in_array($channel->connection_status, ['connected_cloud_api', 'connected_coexistence'], true)) {
            return 'connection_unverified';
        }
        if (SocialMessage::query()->where('social_conversation_id', $conversation->id)->where('id', '>', $op->source_last_message_id)->exists()) {
            return 'conversation_changed';
        }
        $related = FollowUpAction::query()->whereKeyNot($action->id)->whereHas('opportunity', function ($q) use ($op): void {
            $q->where(function ($q) use ($op): void {
                $q->where('social_conversation_id', $op->social_conversation_id);
                if ($op->contact_id !== null) {
                    $q->orWhere('contact_id', $op->contact_id);
                }
            });
        });
        if ((clone $related)->whereIn('status', ['claimed', 'sending', 'delivery_unknown'])->exists()) {
            return 'recipient_execution_unresolved';
        }
        if ((clone $related)->whereNotNull('dispatch_at')->count() >= $settings->maximumAttempts) {
            return 'recipient_attempt_limit';
        }
        if ((clone $related)->where('dispatch_at', '>', now()->subHours(($settings->cadence['enabled'] ?? false) ? (int) $settings->cadence['minimum'] : $settings->minimumIntervalHours))->exists()) {
            return 'recipient_cooldown';
        }
        if (! app(FollowUpDraftService::class)->refreshValidity($action)) {
            return 'draft_obsolete';
        }
        try {
            $content = app(ExecutionContent::class)->build($action);
        } catch (\Throwable) {
            return 'content_unverified';
        }
        $needsApproval = $settings->mode !== 'controlled_auto' || ($action->draft_payload['requires_human_review'] ?? true);
        if ($needsApproval) {
            $user = User::query()->find($action->approved_by_user_id);
            if ($user === null || $user->status !== 'active' || ! $user->canManageSettings() || (int) $user->institution_id !== (int) $action->institution_id
                || $action->approved_draft_version !== $action->draft_version || ! $action->approval_expires_at?->isFuture()
                || $action->approved_content_hash !== $content['hash'] || $action->policy_version !== $settings->policyVersion || $action->eligibility_version !== $settings->eligibilityVersion) {
                return 'approval_obsolete_or_missing';
            }
        }

        return null;
    }
}
