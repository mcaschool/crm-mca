<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;

/** Explicitly scoped infrastructure records; never infer authorization from missing rows. */
final class ExecutionSwitches
{
    public function reason(FollowUpAction $action): ?string
    {
        if (! config('followup.execution_enabled', false)) {
            return 'execution_global_disabled';
        }
        $op = $action->opportunity;
        foreach (['global' => 0, 'institution' => $action->institution_id, 'advisor' => $op->bot_id, 'channel' => $op->social_channel_id] as $scope => $id) {
            $switch = DB::table('follow_up_execution_switches')->where('scope', $scope)->where('subject_id', $id)
                ->when($scope !== 'global', fn ($q) => $q->where('institution_id', $action->institution_id))->first();
            if ($switch === null || ! $switch->enabled) {
                return 'execution_'.$scope.'_disabled';
            }
            if ($scope === 'channel' && ($switch->verified_until === null || now()->gte($switch->verified_until) || ! $switch->evidence_reference)) {
                return 'channel_capacity_unverified';
            }
            $used = FollowUpAction::query()->whereNotNull('dispatch_at')->where('dispatch_at', '>=', now()->subDay())
                ->when($scope === 'advisor', fn ($q) => $q->whereHas('opportunity', fn ($q) => $q->where('bot_id', $id)))
                ->when($scope === 'channel', fn ($q) => $q->whereHas('opportunity', fn ($q) => $q->where('social_channel_id', $id)))
                ->whereKeyNot($action->id)->count();
            if ($scope !== 'global' && $used >= $switch->daily_limit) {
                return 'execution_'.$scope.'_limit';
            }
        }

        return null;
    }

    public function set(User $actor, string $scope, int $id, bool $enabled, int $limit = 1, ?string $evidence = null): void
    {
        $institution = app(CurrentInstitution::class)->idOrFail();
        abort_unless($actor->canManageSettings() && $actor->status === 'active' && (int) $actor->institution_id === $institution, 403);
        abort_unless(in_array($scope, ['global', 'institution', 'advisor', 'channel'], true), 422);
        if ($scope === 'global') {
            // Global activation is infrastructure administration, never a tenant UI operation.
            abort_unless(app()->runningInConsole() && $id === 0, 403);
        } elseif ($scope === 'institution') {
            abort_unless($id === $institution, 404);
        } elseif ($scope === 'advisor') {
            Bot::query()->findOrFail($id);
        } else {
            SocialChannel::query()->findOrFail($id);
        }
        abort_unless($limit >= 1 && $limit <= 10000 && (! $enabled || $scope !== 'channel' || trim((string) $evidence) !== ''), 422);
        DB::transaction(function () use ($actor, $scope, $id, $enabled, $limit, $evidence, $institution): void {
            DB::table('follow_up_execution_switches')->updateOrInsert(['scope' => $scope, 'subject_id' => $id], [
                'institution_id' => $scope === 'global' ? null : $institution, 'enabled' => $enabled,
                'daily_limit' => $limit, 'evidence_reference' => $evidence === null ? null : mb_substr($evidence, 0, 255),
                'verified_until' => $enabled && $scope === 'channel' ? now()->addHour() : null, 'updated_at' => now(), 'created_at' => now(),
            ]);
            app(FollowUpEventRecorder::class)->record('execution.switch_changed', safeData: ['scope' => $scope, 'subject_id' => $id, 'enabled' => $enabled, 'actor_id' => (int) $actor->id]);
        });
    }

    /** Serialize final claims across an institution, including different contacts/channels. */
    public function lock(int $institutionId): void
    {
        DB::table('follow_up_execution_switches')->where('scope', 'global')->where('subject_id', 0)->lockForUpdate()->get();
        DB::table('follow_up_execution_switches')->where('institution_id', $institutionId)->orderBy('id')->lockForUpdate()->get();
    }
}
