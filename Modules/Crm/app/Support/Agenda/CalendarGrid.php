<?php

declare(strict_types=1);

namespace Modules\Crm\Support\Agenda;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Models\Activity;

/**
 * Presentación de la rejilla horaria del Calendario (vistas semana y día). Solo calcula
 * POSICIONES para pintar: franja de horas visible, desplazamiento y duración de cada actividad
 * en minutos y el carril que ocupa cuando hay actividades simultáneas (se reparten el ancho de
 * la columna en vez de taparse). No filtra, no consulta ni cambia nada: recibe lo que el
 * componente ya cargó con sus reglas de visibilidad.
 */
final class CalendarGrid
{
    /** Franja mínima visible (horas locales de quien mira). */
    public const DAY_START = 8;

    public const DAY_END = 18;

    /** Desde cuántas actividades a la vez la semana las agrupa en una sola tarjeta legible. */
    public const CLUSTER = 3;

    /**
     * @param  array<int,Carbon>  $days
     * @param  Collection<string,Collection<int,Activity>>  $byDay
     * @return array{start:int,end:int,hours:list<string>,items:array<int,array{t:int,d:int,l:int,n:int,size:string}>,clusters:array<string,list<array{t:int,d:int,ids:list<int>}>>,now:?array{date:string,t:int}}
     */
    public static function build(array $days, Collection $byDay, string $tz, ?Carbon $now = null): array
    {
        $start = self::DAY_START;
        $end = self::DAY_END;
        $spans = [];

        foreach ($days as $day) {
            $key = $day->toDateString();
            $dayStart = $day->copy()->setTimezone($tz)->startOfDay();
            foreach ($byDay[$key] ?? [] as $a) {
                $from = max(0, (int) round($dayStart->diffInMinutes($a->starts_at->copy()->setTimezone($tz), false)));
                $to = min(24 * 60, (int) round($dayStart->diffInMinutes($a->ends_at->copy()->setTimezone($tz), false)));
                $to = max($to, $from + 15);
                $spans[$key][] = ['id' => $a->id, 'from' => $from, 'to' => $to];
                $start = min($start, intdiv($from, 60));
                $end = max($end, (int) min(24, ceil($to / 60)));
            }
        }

        $items = [];
        $clusters = [];
        foreach ($spans as $key => $list) {
            $groups = [];
            foreach (self::lanes($list) as $id => [$lane, $lanes, $from, $to, $g]) {
                if ($lanes >= self::CLUSTER) {
                    $groups[$g][] = [$id, $from, $to];
                }
                $items[$id] = [
                    't' => $from - $start * 60,
                    'd' => min($to, $end * 60) - $from,
                    'l' => $lane,
                    'n' => $lanes,
                    'size' => self::size($to - $from),
                ];
            }
            foreach ($groups as $rows) {
                usort($rows, fn (array $a, array $b) => $a[1] <=> $b[1]);
                $from = min(array_column($rows, 1));
                $clusters[$key][] = ['t' => $from - $start * 60, 'd' => min(max(array_column($rows, 2)), $end * 60) - $from, 'ids' => array_column($rows, 0)];
            }
        }

        $hours = [];
        for ($h = $start; $h < $end; $h++) {
            $hours[] = sprintf('%02d:00', $h);
        }

        $nowLine = null;
        if ($now !== null) {
            $local = $now->copy()->setTimezone($tz);
            $minute = $local->hour * 60 + $local->minute;
            if ($minute >= $start * 60 && $minute <= $end * 60) {
                $nowLine = ['date' => $local->toDateString(), 't' => $minute - $start * 60];
            }
        }

        return ['start' => $start, 'end' => $end, 'hours' => $hours, 'items' => $items, 'clusters' => $clusters, 'now' => $nowLine];
    }

    /**
     * Reparte en carriles las actividades de un día: cada grupo de actividades que se solapan
     * comparte el ancho a partes iguales; la siguiente actividad libre vuelve a ocupar todo.
     *
     * @param  list<array{id:int,from:int,to:int}>  $list
     * @return array<int,array{0:int,1:int,2:int,3:int,4:int}> id => [carril, carriles del grupo, desde, hasta, grupo]
     */
    private static function lanes(array $list): array
    {
        usort($list, fn (array $a, array $b) => [$a['from'], $b['to']] <=> [$b['from'], $a['to']]);

        $out = [];
        $group = [];
        $laneEnds = [];
        $groupEnd = 0;
        $g = 0;
        $flush = function () use (&$group, &$laneEnds, &$out, &$groupEnd, &$g): void {
            foreach ($group as [$id, $lane, $from, $to]) {
                $out[$id] = [$lane, count($laneEnds), $from, $to, $g];
            }
            $g++;
            $group = [];
            $laneEnds = [];
            $groupEnd = 0;
        };

        foreach ($list as $s) {
            if ($group !== [] && $s['from'] >= $groupEnd) {
                $flush();
            }
            $lane = null;
            foreach ($laneEnds as $i => $laneEnd) {
                if ($laneEnd <= $s['from']) {
                    $lane = $i;
                    break;
                }
            }
            $lane ??= count($laneEnds);
            $laneEnds[$lane] = $s['to'];
            $group[] = [$s['id'], $lane, $s['from'], $s['to']];
            $groupEnd = max($groupEnd, $s['to']);
        }
        $flush();

        return $out;
    }

    /** Altura disponible → cuánto texto cabe en la tarjeta (xs: una línea; lg: título y datos). */
    private static function size(int $minutes): string
    {
        return match (true) {
            $minutes <= 20 => 'xs',
            $minutes <= 35 => 'sm',
            $minutes <= 65 => 'md',
            default => 'lg',
        };
    }

    /** Icono por tipo de actividad (distingue llamada, videollamada, tarea…). */
    public static function typeIcon(ActivityType $type): string
    {
        return match ($type) {
            ActivityType::Call => 'phone',
            ActivityType::VideoCall => 'video',
            ActivityType::FollowUp => 'refresh',
            ActivityType::PendingEmail => 'mail',
            ActivityType::InPersonMeeting => 'users',
            ActivityType::Task => 'check',
            ActivityType::Other => 'calendar',
        };
    }

    /** Icono del medio, o null cuando no hay medio («Ninguno»). */
    public static function mediumIcon(ActivityMedium $medium): ?string
    {
        return match ($medium) {
            ActivityMedium::GoogleMeet, ActivityMedium::Zoom => 'video',
            ActivityMedium::WhatsApp => 'message-circle',
            ActivityMedium::Phone => 'phone',
            ActivityMedium::InPerson => 'map-pin',
            ActivityMedium::Other => 'external-link',
            ActivityMedium::None => null,
        };
    }

    /** Tono del sistema de diseño para cada estado. */
    public static function statusTone(ActivityStatus $status): string
    {
        return match ($status) {
            ActivityStatus::Scheduled => 'blue',
            ActivityStatus::Completed => 'success',
            ActivityStatus::Cancelled => 'neutral',
            ActivityStatus::NoShow => 'warning',
        };
    }
}
