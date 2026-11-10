<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Support\AdvisorDispatcher;
use Modules\Social\Support\AdvisorQueuePlan;

/**
 * Procesa la cola de respuestas del asesor en redes sociales y TERMINA (sin procesos permanentes):
 * lo lanza el scheduler (cron del hosting → schedule:run) SOLO cuando la cola tiene trabajos
 * ejecutables en el próximo minuto (ver routes/console.php y AdvisorQueuePlan).
 *
 * Al arrancar vuelve a radiografiar la cola y decide cuánto escuchar:
 *  - sin trabajos ejecutables en la ventana → no arranca queue:work (coste: una consulta);
 *  - todos ya disponibles → procesa y termina al vaciar la cola (--stop-when-empty);
 *  - alguno retrasado dentro de la ventana (espera «está escribiendo…» o la del canal) → escucha
 *    justo hasta que venza más un margen, nunca más de --max-time. Así un trabajo con retraso se
 *    atiende en la misma ejecución, sin esperar al minuto siguiente y sin los 50 s fijos de antes.
 *
 * Deja un latido propio solo cuando realmente ejecuta; el latido que habilita el asesor en el
 * panel lo mantiene el planificador (SchedulerHeartbeat). --stop-when-empty explícito conserva el
 * comportamiento clásico (procesar lo disponible y salir), útil en pruebas.
 */
class AdvisorWorkerCommand extends Command
{
    protected $signature = 'social:advisor-worker
        {--max-time=50 : Segundos máximos de escucha por ejecución (tope)}
        {--stop-when-empty : Procesar lo disponible y terminar (ignora la radiografía de la cola)}
        {--memory=128 : Límite de memoria del worker en MB (0 = sin límite; solo pruebas en proceso largo)}';

    protected $description = 'Procesa las respuestas pendientes del asesor inteligente en redes sociales (cola persistente, bajo demanda).';

    public function handle(): int
    {
        if (! AdvisorDispatcher::queueIsPersistent()) {
            $this->warn('La cola es «sync»: no hay despacho persistente que procesar.');

            return self::SUCCESS;
        }

        $cap = max(5, (int) $this->option('max-time'));
        $explicitStop = (bool) $this->option('stop-when-empty');
        $plan = AdvisorQueuePlan::inspect();

        if (! $explicitStop && ! $plan->needsWorker()) {
            $this->line(sprintf('Cola «%s» sin trabajos ejecutables en los próximos %d s: el worker no arranca.', AdvisorDispatcher::queue(), $plan->window));

            return self::SUCCESS;
        }

        $stopWhenEmpty = $explicitStop || $plan->stopWhenEmpty();
        $maxTime = $explicitStop ? $cap : $plan->maxTime($cap);

        AdvisorDispatcher::touch();
        $this->line(sprintf(
            'Worker del asesor: %d listos · %d retrasados · %d reservados · escucha %s.',
            $plan->ready,
            $plan->delayed,
            $plan->reserved,
            $stopWhenEmpty ? 'hasta vaciar la cola (tope '.$maxTime.' s)' : 'hasta '.$maxTime.' s',
        ));

        return (int) $this->call('queue:work', [
            'connection' => config('queue.default'),
            '--queue' => AdvisorDispatcher::queue(),
            '--stop-when-empty' => $stopWhenEmpty,
            '--max-time' => $maxTime,
            '--tries' => 3,
            '--sleep' => 1,
            // Mismo valor por defecto que queue:work. Las pruebas lo desactivan: dentro de una batería
            // larga el proceso PHP ya supera 128 MB y el worker se detendría tras el primer trabajo.
            '--memory' => max(0, (int) $this->option('memory')),
        ]);
    }
}
