<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialOutboundService;

final class FollowUpExecutor
{
    public function __construct(private readonly FollowUpPolicyEngine $policy, private readonly ExecutionContent $content, private readonly ExecutionSwitches $switches, private readonly FollowUpEventRecorder $events, private readonly SocialOutboundService $outbound) {}

    /** @param array<int,string> $bindings */
    public function approve(User $actor, int $id, int $version, array $bindings = [], ?string $expectedContentHash = null): void
    {
        abort_unless($actor->canManageSettings() && $actor->status === 'active' && (int) $actor->institution_id === app(CurrentInstitution::class)->idOrFail(), 403);
        $approved = DB::transaction(function () use ($actor, $id, $version, $bindings, $expectedContentHash): bool {
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($action->status, ['draft', 'pending_approval', 'approved', 'scheduled'], true) || $action->draft_version !== $version) {
                throw ValidationException::withMessages(['execution' => 'approval_context_obsolete']);
            }
            if (! app(FollowUpDraftService::class)->refreshValidity($action)) {
                return false;
            }
            $action->execution_parameters = $bindings;
            $content = $this->content->build($action);
            if ($expectedContentHash !== null && ! hash_equals($expectedContentHash, $content['hash'])) {
                throw ValidationException::withMessages(['execution' => 'approval_preview_changed']);
            }
            $decision = $this->policy->evaluate(new PolicyRequest($action->opportunity, 'schedule', $action->channel, $action->purpose, $action->scheduled_at, $action->expires_at, $action->social_whatsapp_template_id, $actor, $action));
            if (! $decision->allows()) {
                throw ValidationException::withMessages(['execution' => $decision->reasonCode]);
            }
            // A retried approval must not extend its lifetime or duplicate its audit
            // event. Revalidation above still applies to every request.
            if ($action->status === 'scheduled' && $action->approved_by_user_id === $actor->id
                && $action->approved_draft_version === $version && $action->approved_content_hash === $content['hash']
                && $action->approved_at !== null && $action->approval_expires_at?->isFuture()) {
                return true;
            }
            $action->forceFill(['status' => 'scheduled', 'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'approved_draft_version' => $version,
                'approved_content_hash' => $content['hash'], 'approval_expires_at' => now()->addHours(24)->min($action->draft_expires_at), 'execution_error' => null])->save();
            $this->events->record('execution.approved', action: $action, safeData: ['draft_version' => $version, 'content_hash' => $content['hash'], 'approver_id' => (int) $actor->id]);

            return true;
        }, 3);
        if (! $approved) {
            throw ValidationException::withMessages(['execution' => 'approval_context_obsolete']);
        }
    }

    public function execute(int $id): void
    {
        $token = DB::transaction(function () use ($id): ?string {
            $this->switches->lock(app(CurrentInstitution::class)->idOrFail());
            $action = FollowUpAction::query()->lockForUpdate()->find($id);
            if ($action === null || $action->status !== 'scheduled' || $action->dispatch_at !== null) {
                return null;
            }
            if (! $this->allowed($action)) {
                return null;
            }
            $token = (string) Str::uuid();
            $action->forceFill(['status' => 'claimed', 'execution_token' => $token, 'claimed_at' => now()])->save();
            $this->events->record('execution.claimed', action: $action, safeData: ['attempt_id' => $token]);

            return $token;
        }, 3);
        if ($token === null) {
            return;
        }
        // Second fresh transaction rechecks changes since the claim, immediately before dispatch.
        $prepared = DB::transaction(function () use ($id, $token): ?array {
            $this->switches->lock(app(CurrentInstitution::class)->idOrFail());
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($id);
            if ($action->status !== 'claimed' || $action->execution_token !== $token || ! $this->allowed($action)) {
                return null;
            }
            $content = $this->content->build($action);
            $message = $this->outbound->prepareFollowUp($action->opportunity->socialConversation, $content['text'], (int) $action->opportunity->bot_id, (int) $action->id, $content['template']);
            $action->forceFill(['status' => 'sending', 'dispatch_at' => now(), 'social_message_id' => $message->id])->save();
            $this->events->record('execution.dispatch_committed', action: $action, safeData: ['message_id' => (int) $message->id, 'attempt_id' => $token]);

            return [$message, $content];
        }, 3);
        if ($prepared === null) {
            return;
        }
        [$message, $content] = $prepared;
        $this->outbound->dispatchFollowUp($message, $content['template'], $content['parameters']);
        $this->reconcile($id);
    }

    private function allowed(FollowUpAction $action): bool
    {
        try {
            $decision = $this->policy->evaluate(new PolicyRequest($action->opportunity, 'send', $action->channel, $action->purpose, now(), $action->expires_at, $action->social_whatsapp_template_id, action: $action));
            $reason = $decision->allows() ? null : $decision->reasonCode;
        } catch (\Throwable) {
            $reason = 'execution_validation_failed';
        }
        if ($reason === null) {
            return true;
        }
        $action->forceFill(['status' => 'suppressed', 'execution_error' => $reason, 'block_reason' => $reason])->save();
        $this->events->record('execution.blocked', action: $action, reason: $reason);

        return false;
    }

    public function reconcile(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($id);
            if ($action->status === 'claimed' && $action->claimed_at?->lt(now()->subMinutes(3))) {
                $action->forceFill(['status' => 'suppressed', 'execution_error' => 'claim_expired'])->save();
                $this->events->record('execution.recovered', action: $action, reason: 'claim_expired');

                return;
            }
            if (! in_array($action->status, ['sending', 'delivery_unknown', 'sent', 'delivered'], true)) {
                return;
            }
            $message = SocialMessage::query()->find($action->social_message_id);
            $status = $message?->status;
            if ($status === 'failed_window') {
                $status = 'failed';
            }
            if (! in_array($status, ['sent', 'delivered', 'read', 'failed', 'delivery_unknown'], true)) {
                if (! $action->dispatch_at?->lt(now()->subMinutes(3))) {
                    return;
                }
                $status = 'delivery_unknown';
            }
            if (in_array($status, ['sent', 'delivered', 'read'], true) && ! $message?->external_message_id) {
                $status = 'delivery_unknown';
            }
            $rank = ['sending' => 0, 'delivery_unknown' => 1, 'sent' => 2, 'failed' => 3, 'delivered' => 4, 'read' => 5];
            if ($status === $action->status || $rank[$status] < $rank[$action->status]) {
                return;
            }
            $action->forceFill(['status' => $status, 'execution_error' => in_array($status, ['failed', 'delivery_unknown'], true) ? $status : null])->save();
            $this->events->record('execution.'.$status, action: $action, safeData: ['message_id' => $action->social_message_id, 'external_id' => $message?->external_message_id, 'provider_code' => isset($message->ai_meta['send_error_code']) ? (string) $message->ai_meta['send_error_code'] : null]);
        }, 3);
    }
}
