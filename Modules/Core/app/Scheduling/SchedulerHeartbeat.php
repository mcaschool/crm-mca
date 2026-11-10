<?php

declare(strict_types=1);

namespace Modules\Core\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Latido del planificador. Lo escribe el tick en proceso (SchedulerTick) en cada pasada de
 * `schedule:run`. Un latido reciente significa: el cron del hosting y el planificador funcionan y,
 * si hay trabajo encolado, los procesos bajo demanda (worker del asesor, publicador, correos de
 * agenda) arrancarán en el siguiente minuto. Es independiente del latido del worker, que solo se
 * escribe cuando el worker llega a ejecutarse.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'crm.scheduler.seen_at';

    /** Un latido más antiguo que esto = el planificador no está corriendo. */
    public const MAX_AGE_MINUTES = 3;

    public static function touch(): void
    {
        Cache::forever(self::KEY, now()->toIso8601String());
    }

    public static function lastSeen(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::KEY);

            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function running(): bool
    {
        $seen = self::lastSeen();

        return $seen !== null && $seen->gt(now()->subMinutes(self::MAX_AGE_MINUTES));
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
