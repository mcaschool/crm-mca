<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use Illuminate\Support\Facades\Cache;

/**
 * Disyuntor del servidor de medios (fase 4I). Tras un fallo de CONEXIÓN con LiveKit (o una respuesta
 * 5xx), se marca «no disponible» durante `breaker_seconds` en la caché compartida: mientras dure, las
 * operaciones fallan al instante con media_unavailable en lugar de repetir los tiempos de espera, y no
 * se inician llamadas que sonarían sin poder conectarse. Pasado el plazo, la siguiente operación vuelve
 * a probar (y la tarea programada teamcalls:media-sweep lo hace cada minuto). 0 desactiva el disyuntor.
 */
final class MediaHealth
{
    private const KEY = 'teamcalls:media:down_until';

    public function __construct(private readonly int $cooldownSeconds) {}

    public static function fromConfig(): self
    {
        return new self(max(0, min(600, (int) config('teamcalls.media.livekit.breaker_seconds', 30))));
    }

    public function isDown(): bool
    {
        return $this->cooldownSeconds > 0 && $this->downUntil() > now()->getTimestamp();
    }

    /** Segundos hasta el siguiente intento real (0 si no está marcado). */
    public function retryAfter(): int
    {
        return max(0, $this->downUntil() - now()->getTimestamp());
    }

    public function markDown(): void
    {
        if ($this->cooldownSeconds > 0) {
            Cache::put(self::KEY, now()->getTimestamp() + $this->cooldownSeconds, $this->cooldownSeconds);
        }
    }

    public function markUp(): void
    {
        if (Cache::has(self::KEY)) {
            Cache::forget(self::KEY);
        }
    }

    private function downUntil(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }
}
