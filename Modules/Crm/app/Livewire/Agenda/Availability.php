<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Livewire\Agenda\Concerns\ResolvesAgendaOwner;
use Modules\Crm\Models\AvailabilityException;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Policies\ActivityPolicy;

/**
 * «Mi disponibilidad»: zona horaria, varios intervalos por día de la semana (un día sin
 * intervalos queda deshabilitado), excepciones de fecha (ausencias/vacaciones u horario
 * extra), duración por defecto, márgenes antes/después, antelación mínima y horizonte
 * máximo. Solo reglas internas; sin calendarios externos.
 */
#[Layout('layouts.app')]
class Availability extends Component
{
    use ResolvesAgendaOwner;

    public string $timezone = 'UTC';

    public int $defaultDuration = 30;

    public int $bufferBefore = 0;

    public int $bufferAfter = 0;

    public int $minNotice = 0;

    public int $maxHorizon = 90;

    /** @var array<int,array<int,array{start:string,end:string}>> weekday (0=domingo) => intervalos */
    public array $weekly = [];

    public string $exFrom = '';

    public string $exTo = '';

    public string $exKind = 'unavailable';

    public bool $exAllDay = true;

    public string $exStart = '';

    public string $exEnd = '';

    public string $exReason = '';

    public string $notice = '';

    public function mount(): void
    {
        $this->resolveOwner();
        $p = MeetingProfile::forUser($this->ownerId);
        $this->fill([
            'timezone' => $p->timezone,
            'defaultDuration' => $p->default_duration_minutes,
            'bufferBefore' => $p->buffer_before_minutes,
            'bufferAfter' => $p->buffer_after_minutes,
            'minNotice' => $p->min_notice_minutes,
            'maxHorizon' => $p->max_horizon_days,
        ]);
        $this->weekly = array_fill(0, 7, []);
        foreach (AvailabilityRule::query()->where('user_id', $this->ownerId)->orderBy('start_time')->get() as $r) {
            $this->weekly[$r->weekday][] = ['start' => substr($r->start_time, 0, 5), 'end' => substr($r->end_time, 0, 5)];
        }
    }

    public function addInterval(int $weekday): void
    {
        if ($weekday >= 0 && $weekday <= 6) {
            $this->weekly[$weekday][] = ['start' => '09:00', 'end' => '13:00'];
        }
    }

    public function removeInterval(int $weekday, int $index): void
    {
        unset($this->weekly[$weekday][$index]);
        $this->weekly[$weekday] = array_values($this->weekly[$weekday] ?? []);
    }

    public function save(): void
    {
        $this->guardOwner();
        $this->validate([
            'timezone' => ['required', 'timezone:all'],
            'defaultDuration' => ['required', 'integer', 'min:5', 'max:720'],
            'bufferBefore' => ['required', 'integer', 'min:0', 'max:240'],
            'bufferAfter' => ['required', 'integer', 'min:0', 'max:240'],
            'minNotice' => ['required', 'integer', 'min:0', 'max:43200'],
            'maxHorizon' => ['required', 'integer', 'min:1', 'max:730'],
            'weekly' => ['array'],
            'weekly.*' => ['array'],
            'weekly.*.*.start' => ['required', 'date_format:H:i'],
            'weekly.*.*.end' => ['required', 'date_format:H:i'],
        ]);

        $rows = [];
        foreach ($this->weekly as $weekday => $intervals) {
            $weekday = (int) $weekday;
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }
            $sorted = collect($intervals)->sortBy('start')->values();
            $prevEnd = null;
            foreach ($sorted as $i => $int) {
                if ($int['end'] <= $int['start']) {
                    throw ValidationException::withMessages(["weekly.$weekday" => __('El fin de cada intervalo debe ser posterior al inicio.')]);
                }
                if ($prevEnd !== null && $int['start'] < $prevEnd) {
                    throw ValidationException::withMessages(["weekly.$weekday" => __('Los intervalos de un mismo día no pueden solaparse.')]);
                }
                $prevEnd = $int['end'];
                $rows[] = ['weekday' => $weekday, 'start_time' => $int['start'].':00', 'end_time' => $int['end'].':00'];
            }
        }

        DB::transaction(function () use ($rows): void {
            $profile = MeetingProfile::query()->firstOrNew(['user_id' => $this->ownerId]);
            if (! $profile->exists) {
                $profile->timezone = $this->timezone;
            }
            $profile->fill([
                'timezone' => $this->timezone,
                'default_duration_minutes' => $this->defaultDuration,
                'buffer_before_minutes' => $this->bufferBefore,
                'buffer_after_minutes' => $this->bufferAfter,
                'min_notice_minutes' => $this->minNotice,
                'max_horizon_days' => $this->maxHorizon,
            ])->save();

            AvailabilityRule::query()->where('user_id', $this->ownerId)->delete();
            foreach ($rows as $row) {
                AvailabilityRule::query()->create($row + ['user_id' => $this->ownerId]);
            }
        });

        $this->notice = __('Disponibilidad guardada.');
    }

    public function addException(): void
    {
        $this->guardOwner();
        $this->validate([
            'exFrom' => ['required', 'date_format:Y-m-d'],
            'exTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:exFrom'],
            'exKind' => ['required', 'in:unavailable,available'],
            'exAllDay' => ['boolean'],
            'exStart' => [$this->exAllDay ? 'nullable' : 'required', 'nullable', 'date_format:H:i'],
            'exEnd' => [$this->exAllDay ? 'nullable' : 'required', 'nullable', 'date_format:H:i'],
            'exReason' => ['nullable', 'string', 'max:191'],
        ]);
        if (! $this->exAllDay && $this->exEnd <= $this->exStart) {
            throw ValidationException::withMessages(['exEnd' => __('El fin debe ser posterior al inicio.')]);
        }

        AvailabilityException::query()->create([
            'user_id' => $this->ownerId,
            'date_from' => $this->exFrom,
            'date_to' => $this->exTo,
            'kind' => $this->exKind,
            'start_time' => $this->exAllDay ? null : $this->exStart.':00',
            'end_time' => $this->exAllDay ? null : $this->exEnd.':00',
            'reason' => trim($this->exReason) !== '' ? trim($this->exReason) : null,
        ]);
        $this->reset('exFrom', 'exTo', 'exStart', 'exEnd', 'exReason');
        $this->exAllDay = true;
        $this->notice = __('Excepción añadida.');
    }

    public function removeException(int $id): void
    {
        $this->guardOwner();
        AvailabilityException::query()->where('user_id', $this->ownerId)->whereKey($id)->delete();
        $this->notice = __('Excepción eliminada.');
    }

    public function render(): View
    {
        $actor = auth()->user();
        $canPick = (new ActivityPolicy)->manageOthers($actor);

        return view('crm::livewire.agenda.availability', [
            'weekdays' => [1 => __('Lunes'), 2 => __('Martes'), 3 => __('Miércoles'), 4 => __('Jueves'), 5 => __('Viernes'), 6 => __('Sábado'), 0 => __('Domingo')],
            'timezones' => DateTimeZone::listIdentifiers(),
            'exceptions' => AvailabilityException::query()->where('user_id', $this->ownerId)
                ->whereDate('date_to', '>=', now($this->timezone)->subDays(30)->toDateString())
                ->orderBy('date_from')->get(),
            'canPickUser' => $canPick,
            'users' => $canPick ? $this->assignable() : collect(),
            'ownerName' => $this->assignable()->firstWhere('id', $this->ownerId)->name ?? $actor->name,
        ]);
    }
}
