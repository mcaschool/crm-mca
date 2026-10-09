<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Procesa la cola de respuestas del asesor en redes sociales y TERMINA (sin procesos permanentes):
 * lo lanza el scheduler cada minuto (cron del hosting → schedule:run). Deja un latido que el panel
 * usa para saber que el despacho funciona antes de permitir activar un canal.
 *
 * Permanece ESCUCHANDO la cola durante --max-time (50 s), consultándola cada segundo: un trabajo
 * con retraso (la espera «está escribiendo…» del asesor o la del canal) que vence durante esa
 * ventana se procesa en la misma ejecución, sin esperar al minuto siguiente. --stop-when-empty
 * (terminar en cuanto la cola esté vacía) queda solo como opción explícita para las pruebas: en
 * producción provocaría que un trabajo retrasado unos segundos esperara a la próxima ejecución.
 */
class AdvisorWorkerCommand extends Command
{
    protected $signature = 'social:advisor-worker
        {--max-time=50 : Segundos máximos de trabajo por ejecución}
        {--stop-when-empty : Terminar en cuanto la cola esté vacía (solo pruebas)}';

    protected $description = 'Procesa las respuestas pendientes del asesor inteligente en redes sociales (cola persistente).';

    public function handle(): int
    {
        if (! AdvisorDispatcher::queueIsPersistent()) {
            $this->warn('La cola es «sync»: no hay despacho persistente que procesar.');

            return self::SUCCESS;
        }

        AdvisorDispatcher::touch();

        return (int) $this->call('queue:work', [
            'connection' => config('queue.default'),
            '--queue' => AdvisorDispatcher::queue(),
            '--stop-when-empty' => (bool) $this->option('stop-when-empty'),
            '--max-time' => max(5, (int) $this->option('max-time')),
            '--tries' => 3,
            '--sleep' => 1,
        ]);
    }
}
