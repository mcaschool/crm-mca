<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use App\Models\User;
use Modules\Core\Console\TenantAwareCommand;
use Modules\FollowUp\Services\ExecutionSwitches;

final class StopExecutionCommand extends TenantAwareCommand
{
    protected $signature = 'followup:execution-stop {scope : global|institution|advisor|channel} {subject : 0 para global; ID para otros ámbitos} {--actor= : Administrador responsable} {--institution=} {--all-institutions}';

    protected $description = 'Desactiva nuevos despachos en un ámbito y registra la decisión; nunca activa ni envía.';

    protected function runForAllInstitutions(\Modules\Core\Tenancy\CurrentInstitution $context): int
    {
        $this->error('Indica la institución del administrador responsable con --institution.');

        return self::INVALID;
    }

    protected function handleForInstitution(int $institutionId): int
    {
        $actor = User::query()->where('institution_id', $institutionId)->findOrFail((int) $this->option('actor'));
        app(ExecutionSwitches::class)->set($actor, (string) $this->argument('scope'), (int) $this->argument('subject'), false);
        $this->info('Nuevos despachos deshabilitados. Los intentos ya comprometidos conservan su resultado.');

        return self::SUCCESS;
    }
}
