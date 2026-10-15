<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Console;

use Illuminate\Console\Command;
use Modules\TeamCalls\Services\CallService;

/**
 * Aplica lo vencido de las llamadas que nadie consulta (invitaciones sin contestar → perdidas;
 * conexión que no llega → fallida). Cada minuto desde el planificador del módulo; las consultas y
 * operaciones ya lo aplican por su cuenta, así que este comando solo cierra lo olvidado.
 */
class ExpireStaleCallsCommand extends Command
{
    protected $signature = 'teamcalls:expire-stale {--limit=200 : Máximo de llamadas por ejecución}';

    protected $description = 'Marca como perdidas las invitaciones vencidas y cierra las llamadas que no llegaron a conectarse.';

    public function handle(CallService $calls): int
    {
        $n = $calls->expireStale(max(1, min(1000, (int) $this->option('limit'))));
        $this->info("Llamadas revisadas: {$n}.");

        return self::SUCCESS;
    }
}
