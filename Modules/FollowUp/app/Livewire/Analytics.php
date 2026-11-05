<?php

declare(strict_types=1);

namespace Modules\FollowUp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\FollowUp\Services\FollowUpAnalyticsAggregator;
use Modules\FollowUp\Services\FollowUpInsightsService;
use Modules\FollowUp\Services\FollowUpMetricsService;

#[Layout('layouts.app')]
final class Analytics extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @var array<string,string> */
    #[Url]
    public array $filters = [];

    #[Url]
    public string $evidenceKind = 'opportunity';

    #[Url]
    public string $evidenceMetric = '';

    public function inspectMetric(string $metric): void
    {
        abort_unless(in_array($metric, ['detected', 'executed', 'responders', 'meetings', 'conversions_associated', 'blocked'], true), 404);
        $this->evidenceMetric = $metric;
        $this->evidenceKind = match ($metric) {
            'detected' => 'opportunity',
            'meetings' => 'meeting',
            'conversions_associated' => 'conversion',
            default => 'action',
        };
        $this->resetPage();
        $this->dispatch('analytics-evidence');
    }

    public function updatedEvidenceKind(): void
    {
        $this->evidenceMetric = '';
    }

    /** @var array<string,mixed> */
    #[Locked]
    public array $insights = [];

    public bool $useAi = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->canManageSettings(), 403);
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->subDays(29)->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->insights = [];
    }

    public function reconcile(FollowUpAnalyticsAggregator $aggregator): void
    {
        $aggregator->reconcile();
        $this->insights = [];
        session()->flash('analytics_saved', 'Proyección actualizada. No se modificaron datos comerciales ni políticas.');
    }

    public function generateInsights(FollowUpInsightsService $service): void
    {
        [$report, $previous] = $this->reports();
        $bot = $this->filters['bot_id'] ?? '';
        if ($this->useAi && ! ctype_digit($bot)) {
            $this->addError('insights', 'Selecciona un asesor para reutilizar su proveedor configurado.');

            return;
        }
        $this->insights = $service->recommend(auth()->user(), $report, $previous, $this->useAi ? (int) $bot : null);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function reports(): array
    {
        $service = app(FollowUpMetricsService::class);
        [$start, $end] = $service->period($this->from, $this->to);
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $run = \Illuminate\Support\Facades\DB::table('follow_up_analytics_runs')->where('institution_id', auth()->user()->institution_id)->value('generation');
        $key = 'followup:analytics:'.auth()->user()->institution_id.':'.hash('sha256', json_encode([$run, $this->from, $this->to, $this->filters], JSON_THROW_ON_ERROR));

        return Cache::remember($key, 60, fn () => [$service->report($this->from, $this->to, $this->filters), $service->report($start->subDays($days)->toDateString(), $start->subDay()->toDateString(), $this->filters)]);
    }

    public function render(): View
    {
        try {
            $this->validate(['filters' => 'array', 'filters.*' => 'string|max:64', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);
            app(FollowUpMetricsService::class)->period($this->from, $this->to);
            $this->resetErrorBag('period');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return view('followup::livewire.analytics-invalid');
        }
        abort_unless(in_array($this->evidenceKind, ['opportunity', 'action', 'conversion', 'meeting', 'exclusion', 'generation', 'approval', 'usage'], true), 404);
        [$report, $previous] = $this->reports();
        $service = app(FollowUpMetricsService::class);
        [$start, $end] = $service->period($this->from, $this->to);
        $evidence = $service->query($this->filters);
        $eligible = $service->query($this->filters)->where('kind', 'action')->whereBetween('eligible_at', [$start, $end]);
        $evidence = match ($this->evidenceMetric) {
            '' => $evidence->where('kind', $this->evidenceKind)->whereBetween('occurred_at', [$start, $end]),
            'detected' => $evidence->where('kind', 'opportunity')->whereBetween('occurred_at', [$start, $end]),
            'executed' => $eligible,
            'responders' => $eligible->whereNotNull('response_at'),
            'meetings' => $evidence->where('kind', 'meeting')->whereIn('data->state', ['scheduled', 'completed'])->whereBetween('occurred_at', [$start, $end]),
            'conversions_associated' => $evidence->where('kind', 'conversion')->whereIn('action_id', $eligible->select('action_id')),
            'blocked' => $evidence->where('kind', 'action')->where('data->blocked', true)->whereBetween('occurred_at', [$start, $end]),
            default => abort(404),
        };

        return view('followup::livewire.analytics', [
            'report' => $report, 'previous' => $previous,
            'evidence' => $evidence->latest('occurred_at')->paginate(20),
            'bots' => \Modules\Institutions\Models\Bot::query()->orderBy('assistant_name')->get(['id', 'assistant_name', 'name']),
            'programs' => \Modules\Catalog\Models\Program::query()->orderBy('name_es')->get(['id', 'name_es']),
            'owners' => \App\Models\User::query()->where('institution_id', auth()->user()->institution_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
