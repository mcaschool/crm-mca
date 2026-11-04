<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Services\ActionMaintenanceService;

final class MaintainActionsCommand extends TenantAwareCommand
{
    protected $signature = 'followup:maintain {--institution=} {--all-institutions}';

    protected $description = 'Caduca e invalida acciones locales de seguimiento; nunca envía mensajes.';

    protected function handleForInstitution(int $institutionId): int
    {
        $result = app(ActionMaintenanceService::class)->run();
        $this->line("Institución {$institutionId}: {$result['expired']} caducadas, {$result['invalidated']} invalidadas.");

        return self::SUCCESS;
    }
}
