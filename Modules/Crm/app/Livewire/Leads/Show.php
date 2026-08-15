<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\LeadService;

/**
 * Ficha de un lead: cambiar estado e interes manualmente y anotar contexto.
 * 'enrolled' es terminal (LeadService lo impide).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    // Se guarda el ID (no el modelo) para evitar serializar el Eloquent en cada
    // round-trip de Livewire; el lead se recarga al usarlo (siempre bajo scope).
    public int $leadId;

    public string $status = '';

    public string $interest_level = '';

    public string $notes = '';

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);
        $this->leadId = $lead->getKey();
        $this->status = $lead->status->value;
        $this->interest_level = $lead->interest_level->value;
        $this->notes = (string) $lead->notes;
    }

    private function lead(): Lead
    {
        return Lead::query()->findOrFail($this->leadId);
    }

    public function changeStatus(LeadService $service): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);

        try {
            $service->changeStatus($lead, LeadStatus::from($this->status));
            session()->flash('status', __('Estado actualizado.'));
        } catch (InvalidArgumentException $e) {
            // 'enrolled' es terminal: se revierte la seleccion y se avisa.
            $this->status = $lead->status->value;
            $this->addError('status', $e->getMessage());
        }
    }

    public function changeInterest(LeadService $service): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $service->changeInterest($lead, InterestLevel::from($this->interest_level));
        session()->flash('status', __('Interes actualizado.'));
    }

    public function saveNotes(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $lead->notes = trim($this->notes) ?: null;
        $lead->save();
        session()->flash('status', __('Notas guardadas.'));
    }

    public function render(): View
    {
        $lead = $this->lead()->load('contact', 'program');

        return view('crm::livewire.leads.show', [
            'lead' => $lead,
            'statuses' => LeadStatus::cases(),
            'interestLevels' => InterestLevel::cases(),
            'isTerminal' => $lead->status->isTerminal(),
        ]);
    }
}
