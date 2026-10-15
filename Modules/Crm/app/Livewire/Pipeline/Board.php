<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Pipeline;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Services\LeadTransitionException;
use Modules\Crm\Services\PipelineService;

/**
 * Pipeline Comercial (CAPTACIÓN): tablero Kanban con las cinco etapas de LeadStatus. Mover una
 * tarjeta (arrastrando o con el selector accesible «Cambiar etapa») usa la MISMA regla que la
 * ficha del lead (LeadService::changeStatus): permiso LeadPolicy::update, lead de la institución
 * activa (scope global), etapa esperada para no pisar cambios concurrentes, Matriculado terminal
 * y rastro (evento + auditoría). El tablero nunca cambia una etapa por su cuenta.
 */
#[Layout('layouts.app')]
class Board extends Component
{
    #[Url]
    public string $scope = '';

    #[Url]
    public string $advisor = '';

    #[Url]
    public string $program = '';

    #[Url]
    public string $product = '';

    #[Url]
    public string $interest = '';

    #[Url]
    public string $period = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** @var array<string,int> tarjetas cargadas por columna (carga incremental) */
    public array $limits = [];

    public string $notice = '';

    public bool $noticeError = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
        if (! in_array($this->scope, ['mine', 'team'], true)) {
            // La administración ve el equipo; cada asesor empieza por su cartera.
            $this->scope = auth()->user()->canManageCrm() ? 'team' : 'mine';
        }
        $this->resetLimits();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['scope', 'advisor', 'program', 'product', 'interest', 'period', 'search'], true)) {
            $this->resetLimits();
            $this->notice = '';
        }
    }

    public function loadMore(string $status): void
    {
        if (LeadStatus::tryFrom($status) !== null) {
            $this->limits[$status] = min(500, ($this->limits[$status] ?? PipelineService::PAGE) + PipelineService::PAGE);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['advisor', 'program', 'product', 'interest', 'period', 'search']);
        $this->resetLimits();
    }

    /** Cambio de etapa (arrastrar o selector). $from = etapa que el usuario veía. */
    public function move(int $leadId, string $from, string $to, LeadService $service): void
    {
        $this->authorize('viewAny', Lead::class);
        $fromStatus = LeadStatus::tryFrom($from);
        $toStatus = LeadStatus::tryFrom($to);
        $lead = Lead::query()->with('contact:id,first_name,last_name')->find($leadId); // scope: solo su institución
        if ($fromStatus === null || $toStatus === null || $lead === null) {
            $this->reject(__('Ese lead ya no está disponible.'));

            return;
        }
        if (! Gate::allows('update', $lead)) {
            $this->reject(__('No tienes permiso para cambiar la etapa de este lead.'));

            return;
        }
        if ($fromStatus === $toStatus) {
            return;
        }

        try {
            $service->changeStatus($lead, $toStatus, $fromStatus);
        } catch (LeadTransitionException $e) {
            $this->reject($e->userMessage());

            return;
        }
        $this->notice = __('«:name» pasó a :stage.', ['name' => $lead->contact?->fullName() ?? __('Lead'), 'stage' => __($toStatus->label())]);
        $this->noticeError = false;
    }

    public function render(PipelineService $pipeline, AgendaDirectory $directory): View
    {
        $actor = auth()->user();
        $filters = $this->filters();
        $counts = $pipeline->counts($actor, $filters);
        $columns = [];
        $ids = [];
        foreach (LeadStatus::cases() as $status) {
            $cards = $pipeline->column($actor, $filters, $status, $this->limits[$status->value] ?? PipelineService::PAGE);
            $columns[$status->value] = ['status' => $status, 'cards' => $cards, 'total' => $counts[$status->value]];
            array_push($ids, ...$cards->pluck('id')->all());
        }
        $movable = [];
        foreach ($columns as $col) {
            foreach ($col['cards'] as $lead) {
                $movable[$lead->id] = ! $lead->status->isTerminal() && Gate::forUser($actor)->allows('update', $lead);
            }
        }

        return view('crm::livewire.pipeline.board', [
            'columns' => $columns,
            'calendar' => $pipeline->calendar($actor, $ids),
            'movable' => $movable,
            'statuses' => LeadStatus::cases(),
            'advisors' => $directory->assignableUsers(),
            'programs' => Program::query()->whereIn('id', Lead::query()->whereNotNull('program_id')->select('program_id'))->get(),
            'products' => Lead::query()->distinct()->orderBy('product_type')->pluck('product_type')->filter()->values(),
            'interests' => InterestLevel::cases(),
            'tz' => $directory->timezoneFor($actor->id),
            'total' => array_sum($counts),
            'filtered' => $this->advisor.$this->program.$this->product.$this->interest.$this->period.trim($this->search) !== '',
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'scope' => $this->scope,
            'advisor' => $this->scope === 'team' ? (int) $this->advisor : 0,
            'program' => (int) $this->program,
            'product' => $this->product,
            'interest' => $this->interest,
            'period' => $this->period,
            'search' => mb_substr(trim($this->search), 0, 100),
        ];
    }

    private function resetLimits(): void
    {
        $this->limits = array_fill_keys(LeadStatus::values(), PipelineService::PAGE);
    }

    private function reject(string $message): void
    {
        $this->notice = $message;
        $this->noticeError = true;
    }
}
