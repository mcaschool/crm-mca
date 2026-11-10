<?php

declare(strict_types=1);

namespace Modules\Core\Scheduling;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pasos ligeros y acotados (solo base de datos) que corren DENTRO del proceso de `schedule:run`,
 * en secuencia y sin lanzar procesos PHP nuevos. Cada paso va aislado: un fallo se reporta y no
 * impide los demás. Lo que puede ser largo o depende de servicios externos (worker del asesor,
 * publicador, correos de la agenda, sondeos a Meta) sigue en procesos propios, lanzados solo
 * cuando hay trabajo (ver routes/console.php).
 *
 * Los interruptores de cada módulo se respetan aquí ANTES de invocar el comando: un módulo
 * desactivado no cuesta ni la consulta de instituciones.
 */
final class SchedulerTick
{
    /**
     * Cada minuto: latido del planificador, reconciliación de salientes sin confirmar y, si la
     * ejecución de seguimientos está habilitada, el encolado de acciones vencidas.
     *
     * @return array<string, string> comando => ok | skipped | exit:N | error
     */
    public function minute(): array
    {
        SchedulerHeartbeat::touch();

        return $this->run([
            'social:reconcile-deliveries' => [[], true],
            'followup:dispatch' => [['--all-institutions' => true], (bool) config('followup.execution_enabled', false)],
        ]);
    }

    /**
     * Cada cinco minutos: detección (si está habilitada), mantenimiento y reconciliación comercial
     * de seguimientos. Misma cadencia que antes; solo cambia que no arrancan procesos propios.
     *
     * @return array<string, string>
     */
    public function fiveMinutes(): array
    {
        return $this->run([
            'followup:detect' => [['--all-institutions' => true], (bool) config('followup.detection_enabled', false)],
            'followup:maintain' => [['--all-institutions' => true], true],
            'followup:commercial-sync' => [['--all-institutions' => true], true],
        ]);
    }

    /**
     * @param  array<string, array{0: array<string, mixed>, 1: bool}>  $steps
     * @return array<string, string>
     */
    private function run(array $steps): array
    {
        $results = [];
        foreach ($steps as $command => [$arguments, $enabled]) {
            if (! $enabled) {
                $results[$command] = 'skipped';

                continue;
            }
            try {
                $exit = Artisan::call($command, $arguments);
                $results[$command] = $exit === 0 ? 'ok' : 'exit:'.$exit;
                if ($exit !== 0) {
                    Log::warning('scheduler.tick: paso con salida distinta de cero', ['command' => $command, 'exit' => $exit]);
                }
            } catch (Throwable $e) {
                $results[$command] = 'error';
                report($e);
            }
        }

        return $results;
    }
}
