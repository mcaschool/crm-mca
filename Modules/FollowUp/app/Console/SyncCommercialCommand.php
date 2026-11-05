<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\FollowUpCommercialCoordinator;

final class SyncCommercialCommand extends TenantAwareCommand
{
    protected $signature = 'followup:commercial-sync {--institution=} {--all-institutions}';

    protected $description = 'Reconcilia contexto comercial existente; no crea tareas ni envía comunicaciones.';

    protected function handleForInstitution(int $institutionId): int
    {
        FollowUpOpportunity::query()->select('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                app(FollowUpCommercialCoordinator::class)->sync((int) $row->id);
            }
        });

        return self::SUCCESS;
    }
}
