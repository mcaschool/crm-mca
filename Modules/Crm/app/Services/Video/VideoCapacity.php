<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\VideoRoom;

/**
 * Capacidad simultánea de Zoom por ANFITRIÓN (la licencia limita cuántas reuniones puede tener
 * en curso a la vez un mismo anfitrión, y los anfitriones alternativos cuentan para ese límite).
 * Cada actividad de Zoom que usa una sala institucional guarda su anfitrión (video_host_key); dos
 * actividades del mismo anfitrión no pueden superar la capacidad efectiva en ningún instante.
 * Google Meet no tiene esta capacidad compartida: cada espacio es independiente.
 */
class VideoCapacity
{
    public function __construct(private readonly VideoConnections $connections) {}

    /** Anfitrión de Zoom del enlace (solo salas institucionales; un enlace manual no tiene). */
    public function hostKeyForLink(ActivityMedium $medium, ?string $link): ?string
    {
        return $medium === ActivityMedium::Zoom ? $this->hostKeyForRoomHash(Activity::roomHash($link)) : null;
    }

    public function hostKeyForRoomHash(?string $roomHash): ?string
    {
        if ($roomHash === null) {
            return null;
        }
        $key = VideoRoom::query()->where('provider', VideoConnections::ZOOM)->where('join_url_hash', $roomHash)
            ->whereNotNull('host_key')->orderByDesc('id')->value('host_key');

        return is_string($key) ? $key : null;
    }

    /** Capacidad efectiva configurada (1 por defecto; nunca por encima del máximo autorizado). */
    public function limit(): int
    {
        $max = max(1, (int) config('crm.video.zoom.max_concurrency', 2));
        $value = $this->connections->get(VideoConnections::ZOOM)?->secret('concurrency');

        return max(1, min($max, is_numeric($value) ? (int) $value : (int) config('crm.video.zoom.default_concurrency', 1)));
    }

    /**
     * Actividades programadas del anfitrión que tocan el rango (para evaluar muchos huecos).
     *
     * @return Collection<int,Activity>
     */
    public function busy(string $hostKey, Carbon $from, Carbon $to, ?int $ignoreActivityId = null): Collection
    {
        return Activity::query()->scheduled()->where('video_host_key', $hostKey)
            ->when($ignoreActivityId !== null, fn ($q) => $q->whereKeyNot($ignoreActivityId))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['id', 'starts_at', 'ends_at']);
    }

    /** ¿Cabe una reunión más del anfitrión en [start, end)? */
    public function fits(string $hostKey, Carbon $start, Carbon $end, ?int $ignoreActivityId = null): bool
    {
        return $this->fitsIn($this->busy($hostKey, $start, $end, $ignoreActivityId), $start, $end, $this->limit());
    }

    /**
     * Máximo de reuniones simultáneas existentes dentro de [start, end) + la nueva ≤ límite.
     *
     * @param  Collection<int,Activity>  $busy
     */
    public function fitsIn(Collection $busy, Carbon $start, Carbon $end, int $limit): bool
    {
        $points = [];
        foreach ($busy as $a) {
            if ($a->starts_at->lt($end) && $a->ends_at->gt($start)) {
                $points[] = [max($a->starts_at->getTimestamp(), $start->getTimestamp()), 1];
                $points[] = [min($a->ends_at->getTimestamp(), $end->getTimestamp()), -1];
            }
        }
        usort($points, fn (array $x, array $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]); // fin antes que inicio
        $current = 0;
        $peak = 0;
        foreach ($points as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak + 1 <= $limit;
    }
}
