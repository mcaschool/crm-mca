<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Illuminate\Support\Facades\DB;
use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Jobs\ExecuteFollowUp;
use Modules\FollowUp\Models\FollowUpAction;

final class DispatchActionsCommand extends TenantAwareCommand
{
    protected $signature = 'followup:dispatch {--institution=} {--all-institutions}';

    protected $description = 'Encola acciones vencidas; la ejecución revalida todas las autorizaciones.';

    protected function handleForInstitution(int $institutionId): int
    {
        if (! config('followup.execution_enabled', false)) {
            return self::SUCCESS;
        }
        FollowUpAction::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->whereNull('dispatch_at')
            ->where(fn ($q) => $q->whereNull('execution_queued_at')->orWhere('execution_queued_at', '<', now()->subMinutes(5)))
            ->chunkById(100, function ($actions) use ($institutionId): void {
                foreach ($actions as $action) {
                    DB::transaction(function () use ($institutionId, $action): void {
                        $updated = FollowUpAction::query()->whereKey($action->id)->where('status', 'scheduled')
                            ->where(fn ($q) => $q->whereNull('execution_queued_at')->orWhere('execution_queued_at', '<', now()->subMinutes(5)))
                            ->update(['execution_queued_at' => now()]);
                        if ($updated) {
                            ExecuteFollowUp::dispatch($institutionId, (int) $action->id)->onConnection('database')->onQueue('followup-execution')->afterCommit();
                        }
                    });
                }
            });

        return self::SUCCESS;
    }
}
