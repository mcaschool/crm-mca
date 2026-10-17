<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TeamCalls\Contracts\MediaOutage;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Http\CallLaunch;
use Throwable;

/**
 * Comprobación de salud y métricas operativas de TeamCalls (fase 4I), para la supervisión de un entorno de
 * preproducción: habilitación, servidor de medios (alcanzable, latencia, salas abiertas, disyuntor), tiempo
 * real (configuración, sin la clave), cola de entrega de CollaborationRealtime (pendientes y antigüedad) y
 * llamadas vivas. NUNCA imprime secretos. Código de salida: 0 sano, 1 degradado (para el supervisor).
 */
class HealthCommand extends Command
{
    protected $signature = 'teamcalls:health {--json : Salida JSON (para supervisión)} {--outbox-lag=60 : Segundos de retraso de la cola que se consideran degradados}';

    protected $description = 'Salud de TeamCalls: medios, tiempo real, cola de eventos y llamadas (sin secretos).';

    public function handle(MediaRoomService $media): int
    {
        $report = [
            'ui' => ['enabled' => CallLaunch::enabled(), 'stage' => CallLaunch::stage(), 'production_blocked' => true],
            'media' => $this->media($media),
            'realtime' => [
                'broadcaster' => (string) config('broadcasting.default'),
                'scheme' => (string) config('teamcalls.realtime.scheme'),
                'host' => (string) config('teamcalls.realtime.host'),
                'key_configured' => (string) config('broadcasting.connections.reverb.key', '') !== '',
            ],
            'outbox' => $this->outbox(),
            'calls' => $this->calls(),
        ];
        $lag = max(1, (int) $this->option('outbox-lag'));
        $degraded = ($report['media']['configured'] && ! $report['media']['reachable'])
            || ($report['outbox']['oldest_pending_seconds'] ?? 0) > $lag;
        $report['status'] = $degraded ? 'degraded' : 'ok';

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('TeamCalls: '.$report['status']);
            foreach ($report as $section => $values) {
                if (is_array($values)) {
                    $this->line(str_pad($section, 9).' '.json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        return $degraded ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function media(MediaRoomService $media): array
    {
        $configured = config('teamcalls.media.driver') === 'livekit' && ($media instanceof MediaOutage);
        $out = ['driver' => (string) config('teamcalls.media.driver'), 'configured' => $configured, 'reachable' => false,
            'breaker_open' => $media instanceof MediaOutage && $media->outage(), 'rooms' => null, 'latency_ms' => null];
        if (! $configured) {
            return $out;
        }
        $started = microtime(true);
        try {
            // Sonda real: lista las salas (con el disyuntor abierto, falla al instante sin tocar la red).
            $out['rooms'] = count($media->rooms());
            $out['reachable'] = true;
        } catch (Throwable) {
            $out['reachable'] = false;
        }
        $out['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        $out['breaker_open'] = $media->outage();

        return $out;
    }

    /** @return array<string, int|null> */
    private function outbox(): array
    {
        if (! Schema::hasTable('collab_outbox')) {
            return ['pending' => null, 'oldest_pending_seconds' => null, 'failed' => null];
        }
        $pending = DB::table('collab_outbox')->where('status', 'pending');
        $oldest = (clone $pending)->min('available_at');

        return [
            'pending' => (clone $pending)->count(),
            'oldest_pending_seconds' => $oldest !== null ? max(0, now()->getTimestamp() - strtotime((string) $oldest)) : 0,
            'failed' => DB::table('collab_outbox')->where('status', 'failed')->count(),
        ];
    }

    /** @return array<string, int> */
    private function calls(): array
    {
        $live = DB::table('collab_call_calls')->whereIn('status', ['initiating', 'ringing', 'connecting', 'active']);

        return [
            'live' => (clone $live)->count(),
            'ringing' => (clone $live)->whereIn('status', ['initiating', 'ringing'])->count(),
            'active' => (clone $live)->where('status', 'active')->count(),
        ];
    }
}
