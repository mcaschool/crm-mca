<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;

/**
 * Reemplazo directo del Google Sheets: donde Marketing y Admisiones ven y
 * trabajan los prospectos. Listado con filtros. Aislado por el scope global.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public string $area = '';

    #[Url]
    public string $goal = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $source = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    /**
     * @return Collection<int, Lead>
     */
    private function leads(): Collection
    {
        return Lead::query()
            ->with(['contact', 'program'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->area !== '', fn ($q) => $q->where('area', 'like', '%'.$this->area.'%'))
            ->when($this->goal !== '', fn ($q) => $q->where('goal', 'like', '%'.$this->goal.'%'))
            ->when($this->level !== '', fn ($q) => $q->where('level', 'like', '%'.$this->level.'%'))
            ->when($this->source !== '', fn ($q) => $q->where('source', 'like', '%'.$this->source.'%'))
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
    }

    public function render(): View
    {
        return view('crm::livewire.leads.index', [
            'leads' => $this->leads(),
            'statuses' => LeadStatus::cases(),
            'interestLevels' => InterestLevel::cases(),
        ]);
    }
}
