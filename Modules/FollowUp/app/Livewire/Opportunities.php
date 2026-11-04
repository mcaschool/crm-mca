<?php

declare(strict_types=1);

namespace Modules\FollowUp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Institutions\Models\Bot;

#[Layout('layouts.app')]
class Opportunities extends Component
{
    use WithPagination;

    #[Url(as: 'estado')]
    public string $status = '';

    #[Url(as: 'prioridad')]
    public string $priority = '';

    #[Url(as: 'canal')]
    public string $provider = '';

    #[Url(as: 'asesor')]
    public string $bot = '';

    public function mount(): void
    {
        $this->authorize('viewAny', FollowUpOpportunity::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'provider', 'bot'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'priority', 'provider', 'bot']);
        $this->resetPage();
    }

    /** @return Builder<FollowUpOpportunity> */
    private function filteredQuery(): Builder
    {
        return FollowUpOpportunity::query()
            ->with(['bot:id,assistant_name,name', 'socialConversation:id,contact_name,last_message_at'])
            ->when(in_array($this->status, FollowUpOpportunity::STATUSES, true), fn (Builder $q) => $q->where('status', $this->status))
            ->when(in_array($this->priority, FollowUpOpportunity::PRIORITIES, true), fn (Builder $q) => $q->where('priority', $this->priority))
            ->when(in_array($this->provider, ['whatsapp', 'instagram', 'messenger'], true), fn (Builder $q) => $q->where('provider', $this->provider))
            ->when(ctype_digit($this->bot) && (int) $this->bot > 0, fn (Builder $q) => $q->where('bot_id', (int) $this->bot));
    }

    public function render(): View
    {
        $summary = FollowUpOpportunity::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $botIds = FollowUpOpportunity::query()->distinct()->pluck('bot_id');

        return view('followup::livewire.opportunities', [
            'opportunities' => $this->filteredQuery()->orderByDesc('score')->orderByDesc('detected_at')->paginate(25),
            'summary' => $summary,
            'bots' => Bot::query()->whereIn('id', $botIds)->orderBy('assistant_name')->get(['id', 'assistant_name', 'name']),
        ]);
    }
}
