<?php

declare(strict_types=1);

namespace Modules\Core\Scheduling;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comprobaciones `when()` del planificador a prueba de fallos. `Event::filtersPass()` no captura
 * excepciones: una comprobación que lance (p. ej. un fallo transitorio de la base de datos) abortaría
 * `schedule:run` y dejaría sin evaluar los eventos restantes de ese minuto. Aquí el error se reporta
 * y se responde «sí» por precaución: el comando arranca y, si el problema persiste, falla por sí
 * mismo y de forma visible; nunca se omite trabajo por culpa de la comprobación.
 */
final class SchedulerGuards
{
    public static function safe(string $guard, callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable $e) {
            Log::warning('scheduler.guard: la comprobación falló; se lanza el comando por precaución', [
                'guard' => $guard,
                'error' => class_basename($e),
            ]);
            report($e);

            return true;
        }
    }
}
