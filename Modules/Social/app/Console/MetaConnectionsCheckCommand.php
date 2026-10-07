<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Services\MetaConnectionService;

/**
 * Revisa a diario la conexión con Meta de cada empresa (validez, permisos, caducidad), para que
 * el panel avise a tiempo de renovarla. No pide nada a nadie ni cambia nada en Meta.
 */
class MetaConnectionsCheckCommand extends Command
{
    protected $signature = 'social:meta-connections-check';

    protected $description = 'Revisa el estado de la conexión con Meta de cada empresa.';

    public function handle(MetaConnectionService $service, CurrentInstitution $tenancy): int
    {
        $rows = $tenancy->runGlobally(fn () => MetaConnection::query()->where('status', '!=', 'disconnected')->get(['id', 'institution_id']));
        foreach ($rows as $row) {
            $state = $tenancy->runFor((int) $row->institution_id, function () use ($service, $row): string {
                $connection = MetaConnection::query()->find($row->id);

                return $connection === null ? '—' : $service->refreshStatus($connection)->effectiveStatus();
            });
            $this->line("Empresa {$row->institution_id}: {$state}");
        }

        return self::SUCCESS;
    }
}
