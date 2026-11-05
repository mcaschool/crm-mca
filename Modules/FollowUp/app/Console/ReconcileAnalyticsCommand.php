<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Services\FollowUpAnalyticsAggregator;

final class ReconcileAnalyticsCommand extends TenantAwareCommand
{
    protected $signature = 'followup:analytics-reconcile {--institution=} {--all-institutions}';

    protected $description = 'Reconstruye únicamente proyecciones analíticas; sin acciones comerciales ni proveedores.';

    protected function handleForInstitution(int $institutionId): int
    {
        $this->info((string) app(FollowUpAnalyticsAggregator::class)->reconcile());

        return self::SUCCESS;
    }
}
