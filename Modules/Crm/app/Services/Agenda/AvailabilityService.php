<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\AvailabilityException;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\MeetingProfile;

/**
 * Disponibilidad de un asesor calculada SOLO con reglas internas: intervalos semanales,
 * excepciones de fecha (ausencias/vacaciones u horario extra), márgenes antes/después,
 * antelación mínima, horizonte máximo y las actividades PROGRAMADAS (las completadas,
 * canceladas o «no asistió» no bloquean). Todo en UTC; las reglas se interpretan en la
 * zona horaria del perfil del asesor.
 *
 * Un asesor sin ningún intervalo configurado no tiene restricción de horario (solo se
 * comprueban ausencias, solapes, antelación y horizonte).
 */
class AvailabilityService
{
    public function check(int $userId, Carbon $start, Carbon $end, ?int $ignoreActivityId = null): AvailabilityCheck
    {
        $profile = MeetingProfile::forUser($userId);
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();
        $now = now()->utc();

        $issues = [];
        if ($start->lt($now)) {
            $issues[] = 'past';
        } elseif ($profile->min_notice_minutes > 0 && $start->lt($now->copy()->addMinutes($profile->min_notice_minutes))) {
            $issues[] = 'notice';
        }
        if ($profile->max_horizon_days > 0 && $start->gt($now->copy()->addDays($profile->max_horizon_days))) {
            $issues[] = 'horizon';
        }

        $hours = $this->hoursIssue($userId, $profile->timezone, $start, $end);
        if ($hours !== null) {
            $issues[] = $hours;
        }

        $conflicts = $this->conflicts($userId, $start, $end, $profile->buffer_before_minutes, $profile->buffer_after_minutes, $ignoreActivityId);

        return new AvailabilityCheck($issues, $conflicts);
    }

    /**
     * Actividades programadas del asesor que chocan con [start, end] contando los márgenes:
     * cada actividad ocupa [inicio − antes, fin + después], así que dos actividades necesitan
     * una separación de (antes + después) minutos.
     *
     * @return Collection<int,Activity>
     */
    public function conflicts(int $userId, Carbon $start, Carbon $end, int $before, int $after, ?int $ignoreActivityId = null): Collection
    {
        $gap = max(0, $before) + max(0, $after);

        return Activity::query()
            ->scheduled()
            ->where('assigned_to_user_id', $userId)
            ->when($ignoreActivityId !== null, fn ($q) => $q->whereKeyNot($ignoreActivityId))
            ->where('starts_at', '<', $end->copy()->utc()->addMinutes($gap))
            ->where('ends_at', '>', $start->copy()->utc()->subMinutes($gap))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Huecos libres de un día local del asesor (para sugerir horas en el formulario).
     *
     * @return array<int,string> horas locales «HH:MM»
     */
    public function freeSlots(int $userId, string $localDate, int $durationMinutes, ?int $ignoreActivityId = null): array
    {
        $profile = MeetingProfile::forUser($userId);
        $tz = $profile->timezone;
        $day = Carbon::parse($localDate, $tz)->startOfDay();
        $windows = $this->windowsFor($userId, $day);
        if ($windows === null) {
            $windows = [[$day->copy()->setTime(8, 0), $day->copy()->setTime(20, 0)]];
        }

        $slots = [];
        $duration = max(5, $durationMinutes);
        foreach ($windows as [$from, $to]) {
            for ($t = $from->copy(); $t->copy()->addMinutes($duration)->lte($to); $t->addMinutes($duration)) {
                $check = $this->check($userId, $t->copy()->utc(), $t->copy()->addMinutes($duration)->utc(), $ignoreActivityId);
                if ($check->ok()) {
                    $slots[] = $t->format('H:i');
                }
            }
        }

        return array_values(array_unique($slots));
    }

    /** null si cabe; 'absence' si choca con una ausencia; 'outside_hours' si cae fuera de horario. */
    private function hoursIssue(int $userId, string $tz, Carbon $start, Carbon $end): ?string
    {
        $ls = $start->copy()->setTimezone($tz);
        $le = $end->copy()->setTimezone($tz);
        $day = $ls->copy()->startOfDay();

        foreach ($this->exceptionsOn($userId, $day) as $ex) {
            if ($ex->kind !== 'unavailable') {
                continue;
            }
            if ($ex->isAllDay()) {
                return 'absence';
            }
            [$from, $to] = $this->window($day, (string) $ex->start_time, (string) $ex->end_time);
            if ($ls->lt($to) && $le->gt($from)) {
                return 'absence';
            }
        }

        $windows = $this->windowsFor($userId, $day);
        if ($windows === null) {
            return null; // sin horario configurado: no se restringe
        }
        foreach ($windows as [$from, $to]) {
            if ($ls->gte($from) && $le->lte($to)) {
                return null;
            }
        }

        return 'outside_hours';
    }

    /**
     * Ventanas disponibles de un día local: intervalos de su día de la semana + horario
     * extra de excepciones «available». null = el asesor no tiene horario configurado.
     *
     * @return array<int,array{0:Carbon,1:Carbon}>|null
     */
    private function windowsFor(int $userId, Carbon $day): ?array
    {
        $hasRules = AvailabilityRule::query()->where('user_id', $userId)->exists();
        $extra = $this->exceptionsOn($userId, $day)->where('kind', 'available');
        if (! $hasRules && $extra->isEmpty()) {
            return null;
        }

        $windows = [];
        $rules = AvailabilityRule::query()
            ->where('user_id', $userId)
            ->where('weekday', $day->dayOfWeek)
            ->orderBy('start_time')
            ->get();
        foreach ($rules as $rule) {
            $windows[] = $this->window($day, $rule->start_time, $rule->end_time);
        }
        foreach ($extra as $ex) {
            $windows[] = $ex->isAllDay()
                ? [$day->copy()->startOfDay(), $day->copy()->endOfDay()]
                : $this->window($day, (string) $ex->start_time, (string) $ex->end_time);
        }

        return $windows;
    }

    /** @return Collection<int,AvailabilityException> */
    private function exceptionsOn(int $userId, Carbon $day): Collection
    {
        $date = $day->toDateString();

        return AvailabilityException::query()
            ->where('user_id', $userId)
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date)
            ->get();
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function window(Carbon $day, string $from, string $to): array
    {
        [$fh, $fm] = array_map('intval', explode(':', $from) + [0, 0]);
        [$th, $tm] = array_map('intval', explode(':', $to) + [0, 0]);

        return [$day->copy()->setTime($fh, $fm), $day->copy()->setTime($th, $tm)];
    }
}
