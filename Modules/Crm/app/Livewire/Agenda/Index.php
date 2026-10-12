<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Models\Activity;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\AgendaDirectory;

/**
 * Agenda principal: vistas semana (por defecto), día, mes y lista, navegación por fechas
 * y filtros (asesor —solo administración—, tipo, estado y medio). Las horas se guardan en
 * UTC y se muestran en la zona horaria del perfil de quien mira. Los accesos del panel de
 * inicio llegan con filtros en la URL (hoy, próximos 7 días, vencidas).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    #[Url]
    public string $view = 'week';

    #[Url]
    public string $date = '';

    #[Url]
    public string $advisor = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $medium = '';

    /** '1' = actividades programadas cuya hora ya pasó (vencidas). */
    #[Url]
    public string $overdue = '';

    /** Días hacia delante en la vista lista (p. ej. 7 = próximos 7 días). */
    #[Url]
    public string $range = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Activity::class);
        if (! in_array($this->view, ['week', 'day', 'month', 'list'], true)) {
            $this->view = 'week';
        }
        if ($this->date === '' || ! $this->validDate($this->date)) {
            $this->date = now($this->tz())->toDateString();
        }
    }

    public function setView(string $view): void
    {
        if (in_array($view, ['week', 'day', 'month', 'list'], true)) {
            $this->view = $view;
            $this->overdue = '';
            $this->range = '';
        }
    }

    public function previous(): void
    {
        $this->date = $this->shift(-1);
    }

    public function next(): void
    {
        $this->date = $this->shift(1);
    }

    public function today(): void
    {
        $this->date = now($this->tz())->toDateString();
    }

    public function goTo(string $date): void
    {
        if ($this->validDate($date)) {
            $this->date = $date;
            $this->view = 'day';
        }
    }

    public function clearFilters(): void
    {
        $this->advisor = $this->type = $this->status = $this->medium = $this->overdue = $this->range = '';
    }

    public function render(): View
    {
        $user = auth()->user();
        $tz = $this->tz();
        $policy = new ActivityPolicy;
        $canFilterAdvisor = $policy->manageOthers($user);
        [$from, $to] = $this->period($tz);

        $query = ActivityPolicy::visibleQuery(Activity::query(), $user)
            ->with(['lead.contact', 'contact', 'assignee'])
            ->when($canFilterAdvisor && $this->advisor !== '', fn (Builder $q) => $q->where('assigned_to_user_id', (int) $this->advisor))
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->medium !== '', fn (Builder $q) => $q->where('medium', $this->medium));

        if ($this->overdue === '1') {
            $query->where('status', ActivityStatus::Scheduled->value)->where('ends_at', '<', now());
        } else {
            $query->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
                ->where('starts_at', '<', $to->copy()->utc())
                ->where('ends_at', '>', $from->copy()->utc());
        }

        /** @var Collection<int,Activity> $activities */
        $activities = $query->orderBy('starts_at')->limit(500)->get();
        $byDay = $activities->groupBy(fn (Activity $a) => $a->starts_at->copy()->setTimezone($tz)->toDateString());

        return view('crm::livewire.agenda.index', [
            'tz' => $tz,
            'from' => $from,
            'to' => $to,
            'days' => $this->daysOf($from, $to),
            'activities' => $activities,
            'byDay' => $byDay,
            'title' => $this->titleFor($from, $to),
            'canCreate' => $user->can('create', Activity::class),
            'canFilterAdvisor' => $canFilterAdvisor,
            'advisors' => $canFilterAdvisor ? app(AgendaDirectory::class)->assignableUsers() : collect(),
            'types' => ActivityType::cases(),
            'statuses' => ActivityStatus::cases(),
            'media' => ActivityMedium::cases(),
            'todayLocal' => now($tz)->toDateString(),
            'cursor' => Carbon::parse($this->date, $tz),
        ]);
    }

    private function tz(): string
    {
        return app(AgendaDirectory::class)->timezoneFor((int) auth()->id());
    }

    /** @return array{0:Carbon,1:Carbon} inicio y fin (exclusivo) en la zona de quien mira */
    private function period(string $tz): array
    {
        $d = Carbon::parse($this->date, $tz)->startOfDay();
        if ($this->range !== '' && ctype_digit($this->range)) {
            $start = now($tz);
            if ($d->gt($start->copy()->startOfDay())) {
                $start = $d;
            }

            return [$start->copy(), $start->copy()->startOfDay()->addDays(min(90, max(1, (int) $this->range)) + 1)];
        }

        return match ($this->view) {
            'day' => [$d->copy(), $d->copy()->addDay()],
            'month' => [$d->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $d->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->addSecond()],
            'list' => [$d->copy(), $d->copy()->addDays(30)],
            default => [$d->copy()->startOfWeek(Carbon::MONDAY), $d->copy()->startOfWeek(Carbon::MONDAY)->addWeek()],
        };
    }

    /** @return array<int,Carbon> */
    private function daysOf(Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lt($to) && count($days) < 92; $d->addDay()) {
            $days[] = $d->copy();
        }

        return $days;
    }

    private function shift(int $direction): string
    {
        $d = Carbon::parse($this->date, $this->tz());

        $shifted = match ($this->view) {
            'day' => $d->addDays($direction),
            'month' => $d->startOfMonth()->addMonths($direction),
            'list' => $d->addDays(30 * $direction),
            default => $d->addWeeks($direction),
        };

        return $shifted->toDateString();
    }

    private function titleFor(Carbon $from, Carbon $to): string
    {
        if ($this->overdue === '1') {
            return __('Actividades vencidas');
        }
        if ($this->range !== '') {
            return __('Próximos :n días', ['n' => (int) $this->range]);
        }
        $last = $to->copy()->subSecond();

        return match ($this->view) {
            'day' => $from->translatedFormat('l j \d\e F Y'),
            'month' => Carbon::parse($this->date)->translatedFormat('F Y'),
            default => $from->translatedFormat('j M').' – '.$last->translatedFormat('j M Y'),
        };
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }
}
