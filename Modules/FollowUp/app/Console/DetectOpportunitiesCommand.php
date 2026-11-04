<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Services\OpportunityDetector;

class DetectOpportunitiesCommand extends TenantAwareCommand
{
    protected $signature = 'followup:detect {--institution=} {--all-institutions}';

    protected $description = 'Detecta conversaciones inactivas y registra oportunidades de seguimiento sin enviar mensajes.';

    public function __construct(private readonly OpportunityDetector $detector)
    {
        parent::__construct();
    }

    protected function handleForInstitution(int $institutionId): int
    {
        if (! (bool) config('followup.detection_enabled', false)) {
            $this->line("Institucion #{$institutionId}: deteccion desactivada.");

            return self::SUCCESS;
        }

        $result = $this->detector->detectDue();
        $this->line("Institucion #{$institutionId}: {$result['detected']} nuevas, {$result['updated']} actualizadas, {$result['cancelled']} canceladas, {$result['skipped']} omitidas.");

        return self::SUCCESS;
    }
}
