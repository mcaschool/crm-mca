<?php

declare(strict_types=1);

namespace Modules\FollowUp\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\FollowUpActionPlanner;
use Modules\FollowUp\Services\FollowUpDraftService;
use Modules\FollowUp\Services\FollowUpSettingsResolver;
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

    public string $selectedOpportunity = '';

    public string $actionPurpose = 'requested_follow_up';

    public string $scheduledAt = '';

    public string $expiresAt = '';

    public string $templateId = '';

    public string $consentState = 'unknown';

    public string $consentSource = '';

    public string $consentEvidence = '';

    public string $draftText = '';

    public int $draftActionId = 0;

    public int $draftVersion = 0;

    public function generateDraft(int $id, int $version, FollowUpDraftService $service): void
    {
        $service->request(auth()->user(), $id, $version);
    }

    public function openDraft(int $id, FollowUpDraftService $service): void
    {
        $this->authorize('viewAny', FollowUpOpportunity::class);
        $action = FollowUpAction::query()->findOrFail($id);
        if ($service->refreshValidity($action)) {
            $this->draftActionId = $id;
            $this->draftVersion = $action->draft_version;
            $this->draftText = (string) ($action->draft_payload['draft_text'] ?? '');
        }
    }

    public function saveDraft(FollowUpDraftService $service): void
    {
        $service->edit(auth()->user(), $this->draftActionId, $this->draftVersion, $this->draftText);
        $this->reset(['draftActionId', 'draftText', 'draftVersion']);
    }

    public function discardDraft(int $id, int $version, FollowUpDraftService $service): void
    {
        $service->discard(auth()->user(), $id, $version);
    }

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

    public function selectOpportunity(int $id): void
    {
        $opportunity = FollowUpOpportunity::query()->findOrFail($id);
        $this->authorize('view', $opportunity);
        $this->selectedOpportunity = (string) $id;
        $effective = app(FollowUpSettingsResolver::class)->effective((int) $opportunity->institution_id, (int) $opportunity->bot_id);
        $local = CarbonImmutable::now($effective->timezone)->addDay()->setTimeFromTimeString($effective->businessHoursStart);
        while (! in_array($local->isoWeekday(), $effective->allowedDays, true)) {
            $local = $local->addDay();
        }
        $this->scheduledAt = $local->format('Y-m-d\TH:i');
        $this->expiresAt = $local->addDays(7)->format('Y-m-d\TH:i');
    }

    public function recordConsent(ConsentService $service): void
    {
        $opportunity = $this->selected();
        $this->validate(['consentState' => 'required|in:unknown,granted,denied,withdrawn', 'consentSource' => 'nullable|string|max:64', 'consentEvidence' => 'nullable|string|max:2000']);
        $service->record(auth()->user(), $opportunity->contact_id, $opportunity->contact_id === null ? $opportunity->social_conversation_id : null, $opportunity->provider, $this->actionPurpose, $this->consentState, $this->consentSource ?: null, $this->consentEvidence ?: null);
        $this->reset(['consentSource', 'consentEvidence']);
        session()->flash('followup_action', __('Consentimiento registrado con historial e invalidación.'));
    }

    public function proposeAction(FollowUpActionPlanner $planner): void
    {
        $opportunity = $this->selected();
        $this->validate(['scheduledAt' => 'required|date', 'expiresAt' => 'required|date|after:scheduledAt', 'actionPurpose' => 'required|in:requested_follow_up,marketing,operational']);
        $effective = app(FollowUpSettingsResolver::class)->effective((int) $opportunity->institution_id, (int) $opportunity->bot_id);
        $planner->propose(auth()->user(), (int) $opportunity->id, $opportunity->provider, $this->actionPurpose, CarbonImmutable::parse($this->scheduledAt, $effective->timezone)->utc(), CarbonImmutable::parse($this->expiresAt, $effective->timezone)->utc(), ctype_digit($this->templateId) ? (int) $this->templateId : null);
        session()->flash('followup_action', __('Acción local evaluada y registrada. No se envió ningún mensaje.'));
    }

    public function approveAction(int $id, FollowUpActionPlanner $planner): void
    {
        $planner->approve(auth()->user(), $id);
        session()->flash('followup_action', __('Acción aprobada localmente. Aún no existe ejecutor de envío.'));
    }

    public function scheduleAction(int $id, FollowUpActionPlanner $planner): void
    {
        $planner->schedule(auth()->user(), $id);
        session()->flash('followup_action', __('Acción programada como registro local.'));
    }

    public function pauseAction(int $id, FollowUpActionPlanner $planner): void
    {
        $planner->pause(auth()->user(), $id);
    }

    public function cancelAction(int $id, FollowUpActionPlanner $planner): void
    {
        $planner->cancel(auth()->user(), $id);
    }

    private function selected(): FollowUpOpportunity
    {
        abort_unless(ctype_digit($this->selectedOpportunity), 404);
        $opportunity = FollowUpOpportunity::query()->findOrFail((int) $this->selectedOpportunity);
        $this->authorize('view', $opportunity);

        return $opportunity;
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

        $selected = ctype_digit($this->selectedOpportunity) ? FollowUpOpportunity::query()->with(['channel.whatsappTemplates', 'socialConversation', 'contact'])->find((int) $this->selectedOpportunity) : null;
        if ($selected !== null) {
            foreach (FollowUpAction::query()->where('follow_up_opportunity_id', $selected->id)->whereIn('draft_state', ['queued', 'generating', 'review', 'no_contact'])->get() as $action) {
                app(FollowUpDraftService::class)->refreshValidity($action);
            }
        }

        return view('followup::livewire.opportunities', [
            'opportunities' => $this->filteredQuery()->orderByDesc('score')->orderByDesc('detected_at')->paginate(25),
            'summary' => $summary,
            'bots' => Bot::query()->whereIn('id', $botIds)->orderBy('assistant_name')->get(['id', 'assistant_name', 'name']),
            'selected' => $selected,
            'actions' => $selected ? FollowUpAction::query()->where('follow_up_opportunity_id', $selected->id)->latest()->get() : collect(),
            'draftEvents' => $selected ? \Modules\FollowUp\Models\FollowUpEvent::query()->where('follow_up_opportunity_id', $selected->id)->where('event_type', 'like', 'draft.%')->latest('id')->limit(30)->get() : collect(),
            'consents' => $selected ? CommunicationConsent::query()->where($selected->contact_id ? 'contact_id' : 'social_conversation_id', $selected->contact_id ?: $selected->social_conversation_id)->latest()->get() : collect(),
        ]);
    }
}
