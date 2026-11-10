<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Support\AdvisorDispatcher;
use Modules\Social\Support\AdvisorQueuePlan;

/**
 * Procesa la cola de respuestas del asesor en redes sociales y TERMINA (sin procesos permanentes):
 * lo lanza el scheduler (cron del hosting → schedule:run) cada minuto, con tres modos:
 *
 *  - ESCUCHA CONTINUA: mientras alguna cuenta activa tenga el asesor habilitado
 *    (AdvisorDispatcher::listeningRequired), escucha la cola durante --max-time (55 s) consultándola
 *    cada segundo. Un mensaje que llegue en cualquier segundo del minuto se atiende al instante,
 *    igual que un trabajo retrasado que venza en la ventana; nunca espera al minuto siguiente.
 *  - BAJO DEMANDA: sin cuentas habilitadas (solo restos en cola), arranca únicamente si hay
 *    trabajos ejecutables en los próximos 60 s (AdvisorQueuePlan) y escucha lo justo: termina al
 *    vaciar la cola o espera hasta el último retrasado de la ventana más un margen.
 *  - Sin cuentas habilitadas ni trabajos ejecutables: no arranca queue:work (una consulta y sale).
 *
 * El planificador aplica la misma decisión ANTES de lanzar el proceso (routes/console.php), así que
 * con el asesor completamente inactivo no se crea ningún proceso. Deja un latido propio solo cuando
 * ejecuta; el latido que habilita el asesor en el panel lo mantiene el planificador
 * (SchedulerHeartbeat). --stop-when-empty explícito conserva el comportamiento clásico (procesar lo
 * disponible y salir), útil en pruebas.
 */
class AdvisorWorkerCommand extends Command
{
    protected $signature = 'social:advisor-worker
        {--max-time=55 : Segundos máximos de escucha por ejecución (tope; el cron vuelve a lanzar al minuto)}
        {--stop-when-empty : Procesar lo disponible y terminar (ignora el modo de escucha)}
        {--memory=128 : Límite de memoria del worker en MB (0 = sin límite; solo pruebas en proceso largo)}';

    protected $description = 'Procesa las respuestas pendientes del asesor inteligente en redes sociales (cola persistente).';

    public function handle(): int
    {
        if (! AdvisorDispatcher::queueIsPersistent()) {
            $this->warn('La cola es «sync»: no hay despacho persistente que procesar.');

            return self::SUCCESS;
        }

        $cap = max(5, (int) $this->option('max-time'));
        $explicitStop = (bool) $this->option('stop-when-empty');
        $listening = AdvisorDispatcher::listeningRequired();
        $plan = AdvisorQueuePlan::inspect();

        if (! $explicitStop && ! $listening && ! $plan->needsWorker()) {
            $this->line(sprintf('Cola «%s» sin trabajos ejecutables en los próximos %d s y sin cuentas con el asesor activo: el worker no arranca.', AdvisorDispatcher::queue(), $plan->window));

            return self::SUCCESS;
        }

        if ($explicitStop) {
            [$stopWhenEmpty, $maxTime, $mode] = [true, $cap, 'clásico (procesar y salir)'];
        } elseif ($listening) {
            [$stopWhenEmpty, $maxTime, $mode] = [false, $cap, 'escucha continua'];
        } else {
            [$stopWhenEmpty, $maxTime, $mode] = [$plan->stopWhenEmpty(), $plan->maxTime($cap), 'bajo demanda'];
        }

        AdvisorDispatcher::touch();
        $this->line(sprintf(
            'Worker del asesor [%s]: %d listos · %d retrasados · %d reservados · escucha %s.',
            $mode,
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
