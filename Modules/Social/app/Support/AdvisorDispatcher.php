<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Despacho persistente de las respuestas del asesor en Instagram, Messenger y WhatsApp.
 *
 *   webhook → ingesta → 200 inmediato a Meta + job en la cola (tabla `jobs`, cola «social-advisor»)
 *   cron del hosting (cada minuto) → schedule:run → social:advisor-worker → procesa la cola y se va.
 *
 * Sin procesos permanentes (compatible con Hostinger compartido). El worker deja un «latido» en
 * la caché (store database en producción); mientras no se vea un latido reciente, el panel no
 * permite activar el asesor en ningún canal. Además hay un interruptor general de instalación
 * (social.advisor.autoreply_enabled, apagado por defecto): con él apagado no se encola nada.
 */
final class AdvisorDispatcher
{
    public const HEARTBEAT_KEY = 'social.advisor.worker_seen_at';

    /** Un latido más antiguo que esto = el worker no está corriendo. */
    public const HEARTBEAT_MAX_AGE_MINUTES = 3;

    public static function queue(): string
    {
        return (string) config('social.advisor.queue', 'social-advisor');
    }

    /** Interruptor general de la instalación (apagado por defecto). */
    public static function autoreplyEnabled(): bool
    {
        return (bool) config('social.advisor.autoreply_enabled', false);
    }

    /** La cola es persistente (no `sync`): el webhook nunca procesa la respuesta en su ciclo. */
    public static function queueIsPersistent(): bool
    {
        return config('queue.default') !== 'sync';
    }

    public static function touch(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    public static function lastSeen(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::HEARTBEAT_KEY);

            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function workerRunning(): bool
    {
        $seen = self::lastSeen();

        return $seen !== null && $seen->gt(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES));
    }

    /** ¿Se puede activar el asesor en un canal? Interruptor general + cola persistente + worker vivo. */
    public static function ready(): bool
    {
        return self::autoreplyEnabled() && self::queueIsPersistent() && self::workerRunning();
    }

    /** Motivo en lenguaje llano cuando no está listo (para el panel). */
    public static function pendingReason(): ?string
    {
        return match (true) {
            ! self::autoreplyEnabled() => __('Las respuestas automáticas en redes sociales aún no están habilitadas en este servidor.'),
            ! self::queueIsPersistent(), ! self::workerRunning() => __('El procesamiento automático del servidor todavía no está funcionando. Cuando lo esté, podrás activar el asesor aquí.'),
            default => null,
        };
    }
}
