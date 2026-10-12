<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\AvailabilityException;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Services\Video\VideoCapacity;

/**
 * Huecos reservables públicamente de un asesor en un rango (UTC), calculados SOLO con la
 * disponibilidad del Calendario (Fase 1): intervalos semanales en su zona horaria (con horario
 * de verano), excepciones y ausencias, actividades programadas con sus márgenes, antelación
 * mínima y horizonte máximo. Además aplica una política conservadora de sala: una misma sala de
 * Meet/Zoom no admite dos reuniones simultáneas, aunque sean de asesores distintos; y en salas
 * institucionales de Zoom, la capacidad simultánea del anfitrión (VideoCapacity).
 *
 * Para visitantes NO existe la excepción manual: un asesor sin horario configurado no ofrece huecos.
 * Carga reglas, excepciones y actividades una sola vez por consulta.
 */
class BookingSlots
{
    public function __construct(private readonly VideoCapacity $capacity) {}

    /**
     * @return array<int,Carbon> inicios en UTC, ordenados
     */
    public function between(int $userId, Carbon $fromUtc, Carbon $toUtc, int $duration, ?string $roomHash = null, ?int $ignoreActivityId = null): array
    {
        $profile = MeetingProfile::forUser($userId);
        $tz = $profile->timezone ?: 'UTC';
        $duration = max(5, $duration);
        $step = min(30, $duration);
        $gap = max(0, $profile->buffer_before_minutes) + max(0, $profile->buffer_after_minutes);
        $now = now()->utc();
        $earliest = $now->copy()->addMinutes(max(0, $profile->min_notice_minutes));
        $latest = $now->copy()->addDays(max(1, $profile->max_horizon_days));
        $from = $fromUtc->copy()->utc()->max($earliest);
        $to = $toUtc->copy()->utc()->min($latest);
        if (! $from->lt($to)) {
            return [];
        }

        $rules = AvailabilityRule::query()->where('user_id', $userId)->get()->groupBy('weekday');
        if ($rules->isEmpty()) {
            return [];
        }
        $firstDay = $from->copy()->setTimezone($tz)->startOfDay();
        $lastDay = $to->copy()->setTimezone($tz)->startOfDay();
        $exceptions = AvailabilityException::query()->where('user_id', $userId)
            ->whereDate('date_from', '<=', $lastDay->toDateString())
            ->whereDate('date_to', '>=', $firstDay->toDateString())
            ->get();
        $busy = Activity::query()->scheduled()->where('assigned_to_user_id', $userId)
            ->when($ignoreActivityId !== null, fn ($q) => $q->whereKeyNot($ignoreActivityId))
            ->where('starts_at', '<', $to->copy()->addMinutes($duration + $gap))
            ->where('ends_at', '>', $from->copy()->subMinutes($gap))
            ->get(['id', 'starts_at', 'ends_at']);
        $room = $roomHash === null ? new Collection : Activity::query()->scheduled()->where('meeting_room_hash', $roomHash)
            ->when($ignoreActivityId !== null, fn ($q) => $q->whereKeyNot($ignoreActivityId))
            ->where('starts_at', '<', $to->copy()->addMinutes($duration))
            ->where('ends_at', '>', $from)
            ->get(['id', 'starts_at', 'ends_at']);
        // Zoom institucional: la capacidad simultánea del anfitrión se comparte entre asesores.
        $hostKey = $this->capacity->hostKeyForRoomHash($roomHash);
        $hostBusy = $hostKey === null ? new Collection : $this->capacity->busy($hostKey, $from, $to->copy()->addMinutes($duration), $ignoreActivityId);
        $hostLimit = $hostKey === null ? 0 : $this->capacity->limit();

        $slots = [];
        for ($day = $firstDay->copy(); $day->lte($lastDay); $day->addDay()) {
            $date = $day->toDateString();
            $dayExceptions = $exceptions->filter(fn (AvailabilityException $e) => $e->date_from->toDateString() <= $date && $e->date_to->toDateString() >= $date);
            if ($dayExceptions->contains(fn (AvailabilityException $e) => $e->kind === 'unavailable' && $e->isAllDay())) {
                continue;
            }
            $windows = [];
            foreach ($rules->get($day->dayOfWeek, collect()) as $rule) {
                $windows[] = $this->window($day, $rule->start_time, $rule->end_time);
            }
            foreach ($dayExceptions->where('kind', 'available') as $e) {
                $windows[] = $e->isAllDay() ? [$day->copy(), $day->copy()->endOfDay()] : $this->window($day, (string) $e->start_time, (string) $e->end_time);
            }
            $blocked = [];
            foreach ($dayExceptions->where('kind', 'unavailable') as $e) {
                $blocked[] = $this->window($day, (string) $e->start_time, (string) $e->end_time);
            }

            foreach ($windows as [$wStart, $wEnd]) {
                for ($t = $wStart->copy(); $t->copy()->addMinutes($duration)->lte($wEnd); $t->addMinutes($step)) {
                    $start = $t->copy()->utc();
                    $end = $start->copy()->addMinutes($duration);
                    if ($start->lt($from) || ! $start->lt($to)) {
                        continue;
                    }
                    $localEnd = $t->copy()->addMinutes($duration);
                    foreach ($blocked as [$bStart, $bEnd]) {
                        if ($t->lt($bEnd) && $localEnd->gt($bStart)) {
                            continue 2;
                        }
                    }
                    foreach ($busy as $a) {
                        if ($a->starts_at->lt($end->copy()->addMinutes($gap)) && $a->ends_at->gt($start->copy()->subMinutes($gap))) {
                            continue 2;
                        }
                    }
                    foreach ($room as $a) {
                        if ($a->starts_at->lt($end) && $a->ends_at->gt($start)) {
                            continue 2;
                        }
                    }
                    if ($hostKey !== null && ! $this->capacity->fitsIn($hostBusy, $start, $end, $hostLimit)) {
                        continue;
                    }
                    $slots[$start->getTimestamp()] = $start;
                }
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /** ¿El inicio indicado es exactamente uno de los huecos ofrecidos? */
    public function offers(int $userId, Carbon $startUtc, int $duration, ?string $roomHash = null, ?int $ignoreActivityId = null): bool
    {
        $start = $startUtc->copy()->utc();
        foreach ($this->between($userId, $start, $start->copy()->addMinute(), $duration, $roomHash, $ignoreActivityId) as $slot) {
            if ($slot->eq($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Huecos agrupados por fecha en la zona del visitante: [Y-m-d => [[time, start(ISO UTC)]...]].
     *
     * @return array<string,array<int,array{time:string,start:string}>>
     */
    public function groupedForVisitor(int $userId, Carbon $fromUtc, Carbon $toUtc, int $duration, string $visitorTz, ?string $roomHash = null, ?int $ignoreActivityId = null): array
    {
        $out = [];
        foreach ($this->between($userId, $fromUtc, $toUtc, $duration, $roomHash, $ignoreActivityId) as $slot) {
            $local = $slot->copy()->setTimezone($visitorTz);
            $out[$local->toDateString()][] = ['time' => $local->format('H:i'), 'start' => $slot->format('Y-m-d\TH:i:s\Z')];
        }

        return $out;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function window(Carbon $day, string $from, string $to): array
    {
        [$fh, $fm] = array_map('intval', explode(':', $from) + [0, 0]);
        [$th, $tm] = array_map('intval', explode(':', $to) + [0, 0]);

        return [$day->copy()->setTime($fh, $fm), $day->copy()->setTime($th, $tm)];
    }
}
