<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Illuminate\Support\Facades\DB;

/**
 * Radiografía de la cola del asesor («social-advisor») para decidir si hace falta arrancar el
 * worker y cuánto debe escuchar. Una sola consulta agregada sobre la tabla `jobs`.
 *
 * Un trabajo es «ejecutable en la ventana» si estará disponible dentro de WINDOW_SECONDS:
 *  - listo: sin reservar y `available_at` ya vencido;
 *  - retrasado: sin reservar y `available_at` futuro (espera «está escribiendo…» o la del canal);
 *  - reservado: tomado por un worker que pudo morir; la cola lo vuelve a entregar al cumplirse
 *    `retry_after` (90 s por defecto), así que cuenta a partir de ese instante.
 *
 * Con esto el planificador no lanza ningún proceso cuando no hay nada que hacer, y cuando lo hay
 * el worker termina al vaciar la cola, salvo que haya un trabajo retrasado dentro de la ventana:
 * entonces escucha justo hasta que venza (más un margen), nunca los 50 s fijos de antes.
 */
final class AdvisorQueuePlan
{
    public const WINDOW_SECONDS = 60;

    public const MARGIN_SECONDS = 5;

    public function __construct(
        public readonly int $ready,
        public readonly int $delayed,
        public readonly int $reserved,
        /** Segundos hasta el próximo trabajo ejecutable (0 = ya disponible; null = cola vacía). */
        public readonly ?int $nextAvailableIn,
        /** Segundos hasta el ÚLTIMO trabajo ejecutable dentro de la ventana (null = ninguno en la ventana). */
        public readonly ?int $lastInWindowIn,
        public readonly int $window,
    ) {}

    public static function inspect(int $window = self::WINDOW_SECONDS): self
    {
        $table = (string) config('queue.connections.database.table', 'jobs');
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);
        $now = now()->getTimestamp();
        $effective = "CASE WHEN reserved_at IS NULL THEN available_at ELSE reserved_at + {$retryAfter} END";

        // Alias con sufijo: «delayed» es palabra reservada en MySQL.
        /** @var object{ready_n: mixed, delayed_n: mixed, reserved_n: mixed, next_at: mixed, last_in_window_at: mixed}|null $row */
        $row = DB::table($table)
            ->where('queue', AdvisorDispatcher::queue())
            ->selectRaw(
                'SUM(reserved_at IS NULL AND available_at <= ?) AS ready_n, '
                .'SUM(reserved_at IS NULL AND available_at > ?) AS delayed_n, '
                .'SUM(reserved_at IS NOT NULL) AS reserved_n, '
                ."MIN({$effective}) AS next_at, "
                ."MAX(CASE WHEN ({$effective}) <= ? THEN ({$effective}) END) AS last_in_window_at",
                [$now, $now, $now + $window],
            )
            ->first();

        $nextAt = $row !== null && $row->next_at !== null ? (int) $row->next_at : null;
        $lastAt = $row !== null && $row->last_in_window_at !== null ? (int) $row->last_in_window_at : null;

        return new self(
            ready: (int) ($row->ready_n ?? 0),
            delayed: (int) ($row->delayed_n ?? 0),
            reserved: (int) ($row->reserved_n ?? 0),
            nextAvailableIn: $nextAt === null ? null : max(0, $nextAt - $now),
            lastInWindowIn: $lastAt === null ? null : max(0, $lastAt - $now),
            window: $window,
        );
    }

    public function total(): int
    {
        return $this->ready + $this->delayed + $this->reserved;
    }

    /** ¿Hay algún trabajo que se pueda ejecutar dentro de la ventana? Si no, el worker no arranca. */
    public function needsWorker(): bool
    {
        return $this->lastInWindowIn !== null;
    }

    /** Todo lo ejecutable en la ventana ya está disponible: el worker puede terminar al vaciar la cola. */
    public function stopWhenEmpty(): bool
    {
        return $this->lastInWindowIn === 0;
    }

    /** Segundos de escucha: hasta el último retrasado de la ventana más un margen, sin pasar del tope. */
    public function maxTime(int $cap): int
    {
        if ($this->lastInWindowIn === null || $this->stopWhenEmpty()) {
            return $cap;
        }

        return max(self::MARGIN_SECONDS, min($cap, $this->lastInWindowIn + self::MARGIN_SECONDS));
    }

    /** Trabajos de esta cola que agotaron sus intentos (tabla failed_jobs). */
    public static function failedCount(): int
    {
        return DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->where('queue', AdvisorDispatcher::queue())
            ->count();
    }
}
