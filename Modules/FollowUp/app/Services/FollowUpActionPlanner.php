<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;

final class FollowUpActionPlanner
{
    public function __construct(private readonly FollowUpPolicyEngine $policy, private readonly FollowUpSettingsResolver $settings, private readonly FollowUpEventRecorder $events, private readonly AuditService $audit) {}

    public function propose(User $actor, int $opportunityId, string $channel, string $purpose, CarbonInterface $scheduledAt, CarbonInterface $expiresAt, ?int $templateId = null): FollowUpAction
    {
        abort_unless($actor->canManageSettings(), 403);
        $key = hash('sha256', implode('|', [$actor->institution_id, $opportunityId, $channel, $purpose, $scheduledAt->utc()->format('Y-m-d H:i:s'), $templateId ?? 0]));

        return DB::transaction(function () use ($actor, $opportunityId, $channel, $purpose, $scheduledAt, $expiresAt, $templateId, $key): FollowUpAction {
            $opportunity = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
            abort_unless((int) $opportunity->institution_id === (int) $actor->institution_id, 404);
            $existing = FollowUpAction::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $cycle = FollowUpCycle::query()->where('follow_up_opportunity_id', $opportunity->id)->where('sequence', $opportunity->cycle)->lockForUpdate()->first();
            if ($cycle === null) {
                throw ValidationException::withMessages(['opportunity' => 'La oportunidad no tiene un ciclo vigente.']);
            }

            $prepare = $this->policy->evaluate(new PolicyRequest($opportunity, 'prepare', $channel, $purpose, actor: $actor));
            $schedule = $prepare->allows()
                ? $this->policy->evaluate(new PolicyRequest($opportunity, 'schedule', $channel, $purpose, $scheduledAt, $expiresAt, $templateId, $actor))
                : $prepare;
            $effective = $this->settings->effective((int) $opportunity->institution_id, (int) $opportunity->bot_id);
            $totalAttempts = FollowUpAction::query()->where('follow_up_opportunity_id', $opportunity->id)->count() + 1;
            $cycleAttempts = FollowUpAction::query()->where('follow_up_cycle_id', $cycle->id)->count() + 1;
            $status = $schedule->status === 'denied' ? 'suppressed' : ($schedule->status === 'unknown' ? 'draft' : ($effective->mode === 'controlled_auto' ? 'scheduled' : 'pending_approval'));

            $action = FollowUpAction::query()->create([
                'institution_id' => $actor->institution_id, 'follow_up_opportunity_id' => $opportunity->id, 'follow_up_cycle_id' => $cycle->id,
                'channel' => $channel, 'purpose' => $purpose, 'status' => $status, 'scheduled_at' => $scheduledAt, 'expires_at' => $expiresAt,
                'policy_status' => $schedule->status, 'policy_reason_code' => $schedule->reasonCode, 'policy_version' => $schedule->version,
                'eligibility_version' => $effective->eligibilityVersion, 'idempotency_key' => $key, 'approval_required' => $effective->mode !== 'controlled_auto',
                'block_reason' => $schedule->allows() ? null : $schedule->reasonCode, 'social_whatsapp_template_id' => $templateId,
                'attempt_number' => $totalAttempts, 'cycle_attempt_number' => $cycleAttempts,
            ]);
            $this->events->record('action.proposed', $opportunity, $action, $schedule->reasonCode, ['status' => $status, 'stage' => 'schedule']);
            $this->audit->log('follow_up.action_proposed', $action, ['status' => $status, 'policy_status' => $schedule->status, 'reason_code' => $schedule->reasonCode]);

            return $action;
        }, 3);
    }

    public function approve(User $actor, int $actionId): FollowUpAction
    {
        abort_unless($actor->canManageSettings(), 403);
        $candidate = FollowUpAction::query()->findOrFail($actionId);
        abort_unless((int) $candidate->institution_id === (int) $actor->institution_id, 404);

        return DB::transaction(function () use ($actor, $actionId, $candidate): FollowUpAction {
            $opportunity = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($candidate->follow_up_opportunity_id);
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($actionId);
            $action->setRelation('opportunity', $opportunity);
            if (! in_array($action->status, ['pending_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['action' => 'La acción no está pendiente de aprobación.']);
            }
            $decision = $this->policy->evaluate(new PolicyRequest($action->opportunity, 'schedule', $action->channel, $action->purpose, $action->scheduled_at, $action->expires_at, $action->social_whatsapp_template_id, $actor, $action));
            if (! $decision->allows()) {
                throw ValidationException::withMessages(['action' => $decision->explanation]);
            }
            if ($action->status === 'approved') {
                return $action;
            }
            $action->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'policy_status' => $decision->status, 'policy_reason_code' => $decision->reasonCode])->save();
            $this->events->record('action.approved', $action->opportunity, $action, 'approved_by_admin');
            $this->audit->log('follow_up.action_approved', $action, ['policy_status' => $decision->status]);

            return $action;
        }, 3);
    }

    public function schedule(User $actor, int $actionId): FollowUpAction
    {
        $approved = $this->approve($actor, $actionId);

        return DB::transaction(function () use ($actor, $approved): FollowUpAction {
            $opportunity = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($approved->follow_up_opportunity_id);
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($approved->id);
            $action->setRelation('opportunity', $opportunity);
            if ($action->status !== 'approved') {
                throw ValidationException::withMessages(['action' => 'La acción dejó de estar aprobada antes de programarse.']);
            }
            $decision = $this->policy->evaluate(new PolicyRequest($opportunity, 'schedule', $action->channel, $action->purpose, $action->scheduled_at, $action->expires_at, $action->social_whatsapp_template_id, $actor, $action));
            if (! $decision->allows()) {
                throw ValidationException::withMessages(['action' => $decision->explanation]);
            }
            $action->forceFill(['status' => 'scheduled', 'policy_status' => $decision->status, 'policy_reason_code' => $decision->reasonCode])->save();
            $this->events->record('action.scheduled', $opportunity, $action, 'scheduled_locally');
            $this->audit->log('follow_up.action_scheduled', $action, ['scheduled_at' => $action->scheduled_at?->toIso8601String()]);

            return $action;
        }, 3);
    }

    public function pause(User $actor, int $actionId): FollowUpAction
    {
        return $this->transition($actor, $actionId, ['scheduled', 'approved'], 'paused', 'paused_by_admin');
    }

    public function cancel(User $actor, int $actionId): FollowUpAction
    {
        return $this->transition($actor, $actionId, FollowUpAction::ACTIVE_STATUSES, 'cancelled', 'cancelled_by_admin');
    }

    public function reschedule(User $actor, int $id, int $version, CarbonInterface $date): void
    {
        abort_unless($actor->isActive() && $actor->canManageSettings() && (int) $actor->institution_id === app(\Modules\Core\Tenancy\CurrentInstitution::class)->idOrFail(), 403);
        $candidate = FollowUpAction::query()->findOrFail($id);
        DB::transaction(function () use ($actor, $candidate, $id, $version, $date): void {
            $opportunity = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($candidate->follow_up_opportunity_id);
            $action = FollowUpAction::query()->lockForUpdate()->findOrFail($id);
            if ($action->eligibility_version !== $version || ! in_array($action->status, ['pending_approval', 'approved', 'scheduled', 'paused'], true) || $action->dispatch_at !== null || $date->isPast()) {
                throw ValidationException::withMessages(['postponeAt' => 'La acción cambió o ya no permite posponer. Actualiza y revisa su estado.']);
            }
            $decision = $this->policy->evaluate(new PolicyRequest($opportunity, 'schedule', $action->channel, $action->purpose, $date, $action->expires_at, $action->social_whatsapp_template_id, $actor, $action));
            if (! $decision->allows()) {
                throw ValidationException::withMessages(['postponeAt' => $decision->explanation]);
            }
            app(FollowUpDraftService::class)->invalidate($action, 'schedule_changed');
            $action->refresh()->forceFill(['scheduled_at' => $date, 'status' => 'pending_approval', 'eligibility_version' => $version + 1, 'approved_at' => null, 'approved_by_user_id' => null, 'approved_draft_version' => null, 'approved_content_hash' => null, 'approval_expires_at' => null, 'block_reason' => null, 'policy_status' => $decision->status, 'policy_reason_code' => $decision->reasonCode])->save();
            $this->events->record('action.rescheduled', $opportunity, $action, 'schedule_changed');
            $this->audit->log('follow_up.action_rescheduled', $action, ['scheduled_at' => $date->toIso8601String()]);
        }, 3);
    }

    /** @param array<int,string> $from */
    private function transition(User $actor, int $actionId, array $from, string $to, string $reason): FollowUpAction
    {
        abort_unless($actor->canManageSettings(), 403);

        return DB::transaction(function () use ($actor, $actionId, $from, $to, $reason): FollowUpAction {
            $action = FollowUpAction::query()->with('opportunity')->lockForUpdate()->findOrFail($actionId);
            abort_unless((int) $action->institution_id === (int) $actor->institution_id, 404);
            if (! in_array($action->status, $from, true)) {
                throw ValidationException::withMessages(['action' => 'La transición solicitada no es válida.']);
            }
            $action->status = $to;
            $action->block_reason = $reason;
            if ($to === 'paused') {
                $action->paused_at = now();
            }
            if ($to === 'cancelled') {
                $action->cancelled_at = now();
                $action->cancelled_by_user_id = $actor->id;
            }
            $action->save();
            $this->events->record('action.'.$to, $action->opportunity, $action, $reason);
            $this->audit->log('follow_up.action_'.$to, $action, ['reason_code' => $reason]);

            return $action;
        }, 3);
    }
}
