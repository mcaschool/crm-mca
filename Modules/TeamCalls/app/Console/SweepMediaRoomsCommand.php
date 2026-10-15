<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Console;

use Illuminate\Console\Command;
use Modules\TeamCalls\Services\MediaSweeper;

/**
 * Contrasta las salas reales del servidor de medios con las llamadas (por si se perdió algún webhook):
 * cierra salas huérfanas, desconecta a quien no debería estar y da por desconectado a quien ya no está.
 * Sin servidor de medios configurado no hace nada.
 */
class SweepMediaRoomsCommand extends Command
{
    protected $signature = 'teamcalls:media-sweep {--limit=200 : Máximo de llamadas por ejecución}';

    protected $description = 'Revisa las salas del servidor de medios y corrige lo que no coincide con las llamadas.';

    public function handle(MediaSweeper $sweeper): int
    {
        $r = $sweeper->sweep(max(1, min(1000, (int) $this->option('limit'))));
        $this->info("Salas: {$r['rooms']}. Cerradas: {$r['closed']}. Correcciones: {$r['fixes']}.");

        return self::SUCCESS;
    }
}
