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

    #[Url(as: 'oportunidad')]
    public string $selectedOpportunity = '';

    #[Url(as: 'buscar')]
    public string $search = '';

    #[Url(as: 'programa')]
    public string $program = '';

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    #[Url(as: 'orden')]
    public string $sort = 'priority';

    #[Url(as: 'humana')]
    public bool $humanOnly = false;

    #[Url(as: 'aprobacion')]
    public bool $approvalOnly = false;

    #[Url(as: 'consentimiento')]
    public bool $consentOnly = false;

    #[\Livewire\Attributes\Locked]
    public int $approvalId = 0;

    #[\Livewire\Attributes\Locked]
    public int $approvalVersion = 0;

    #[\Livewire\Attributes\Locked]
    public string $approvalHash = '';

    public bool $approvalConfirmed = false;

    public string $postponeAt = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->canManageSettings(), 403);
    }

    public function reviewExecution(int $id): void
    {
        $action = FollowUpAction::query()->findOrFail($id);
        $this->authorize('view', $action->opportunity);
        if (! app(FollowUpDraftService::class)->refreshValidity($action)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['execution' => 'El borrador ya no está vigente. Regenera y vuelve a revisar.']);
        }
        try {
            $content = app(\Modules\FollowUp\Services\ExecutionContent::class)->build($action);
        } catch (\Throwable) {
            throw \Illuminate\Validation\ValidationException::withMessages(['execution' => 'No se puede verificar el contenido o la plantilla. Revisa el borrador y las restricciones del canal.']);
        }
        $this->approvalId = $id;
        $this->approvalVersion = $action->draft_version;
        $this->approvalHash = $content['hash'];
        $this->approvalConfirmed = false;
        $this->selectedOpportunity = (string) $action->follow_up_opportunity_id;
    }

    public function closeApproval(): void
    {
        $this->reset(['approvalId', 'approvalVersion', 'approvalHash', 'approvalConfirmed']);
    }

    public function confirmExecution(\Modules\FollowUp\Services\FollowUpExecutor $executor): void
    {
        $this->validate(['approvalConfirmed' => 'accepted'], ['approvalConfirmed.accepted' => __('Confirma la revisión antes de aprobar.')]);
        $this->approveExecution($this->approvalId, $this->approvalVersion, $this->approvalHash, $executor);
        $this->closeApproval();
        session()->flash('followup_action', 'Contenido aprobado y registrado. No se ha enviado ningún mensaje.');
    }

    public function postponeAction(int $id, int $version, FollowUpActionPlanner $planner): void
    {
        $this->validate(['postponeAt' => 'required|date|after:now']);
        $planner->reschedule(auth()->user(), $id, $version, CarbonImmutable::parse($this->postponeAt, 'UTC'));
        session()->flash('followup_action', 'Fecha actualizada. La aprobación anterior se invalidó; revisa el contenido de nuevo.');
    }

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

    public string $commercialContact = '';

    public string $commercialLead = '';

    public string $commercialConversation = '';

    public string $commercialEvidence = '';

    public string $commercialOwner = '';

    public string $commercialKind = 'task';

    public string $commercialStarts = '';

    public function evaluateCommercial(\Modules\FollowUp\Services\FollowUpCommercialCoordinator $service): void
    {
        $service->sync((int) $this->selected()->id);
    }

    public function linkCommercial(int $version, \Modules\FollowUp\Services\FollowUpCommercialCoordinator $service): void
    {
        $this->validate(['commercialContact' => 'required|integer|min:1', 'commercialLead' => 'nullable|integer|min:1', 'commercialConversation' => 'nullable|integer|min:1', 'commercialEvidence' => 'required|string|max:500']);
        $service->link(auth()->user(), (int) $this->selected()->id, $version, (int) $this->commercialContact, $this->commercialLead === '' ? null : (int) $this->commercialLead, $this->commercialConversation === '' ? null : (int) $this->commercialConversation, $this->commercialEvidence);
        $this->reset('commercialEvidence');
    }

    public function createCommercialTask(int $version, \Modules\FollowUp\Services\FollowUpCommercialCoordinator $service): void
    {
        $this->validate(['commercialOwner' => 'required|integer|min:1', 'commercialStarts' => 'required|date']);
        try {
            $service->createTask(auth()->user(), (int) $this->selected()->id, $version, (int) $this->commercialOwner, $this->commercialKind, $this->commercialStarts);
        } catch (\Modules\Crm\Services\Agenda\ScheduleConflictException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['commercialStarts' => implode(' ', $exception->check->messages())]);
        }
        session()->flash('followup_action', __('Tarea interna registrada en Agenda, sin comunicaciones.'));
    }

    public function holdCommercial(int $version, bool $hold, \Modules\FollowUp\Services\FollowUpCommercialCoordinator $service): void
    {
        $service->setHold(auth()->user(), (int) $this->selected()->id, $version, $hold);
    }

    public function approveExecution(int $id, int $version, string $contentHash, \Modules\FollowUp\Services\FollowUpExecutor $executor): void
    {
        $executor->approve(auth()->user(), $id, $version, expectedContentHash: $contentHash);
    }

    public function stopExecution(\Modules\FollowUp\Services\ExecutionSwitches $switches): void
    {
        $switches->set(auth()->user(), 'institution', (int) auth()->user()->institution_id, false);
    }

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
        if ($this->selectedOpportunity !== '') {
            abort_unless(ctype_digit($this->selectedOpportunity), 404);
            $this->selectOpportunity((int) $this->selectedOpportunity);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'provider', 'bot', 'search', 'program', 'from', 'to', 'sort', 'humanOnly', 'approvalOnly', 'consentOnly'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'priority', 'provider', 'bot', 'search', 'program', 'from', 'to', 'sort', 'humanOnly', 'approvalOnly', 'consentOnly']);
        $this->resetPage();
    }

    public function selectOpportunity(int $id): void
    {
        $opportunity = FollowUpOpportunity::query()->findOrFail($id);
        $this->authorize('view', $opportunity);
        $this->selectedOpportunity = (string) $id;
        $this->resetPage('actionsPage');
        $effective = app(FollowUpSettingsResolver::class)->effective((int) $opportunity->institution_id, (int) $opportunity->bot_id);
        $local = CarbonImmutable::now($effective->timezone)->addDay()->setTimeFromTimeString($effective->businessHoursStart);
        while ($effective->allowedDays !== [] && ! in_array($local->isoWeekday(), $effective->allowedDays, true)) {
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
        session()->flash('followup_action', __('Acción aprobada localmente. La ejecución requiere aprobar el contenido vigente y activar sus controles independientes.'));
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
            ->with(['bot:id,assistant_name,name', 'socialConversation:id,contact_name,last_message_at', 'lead.program', 'commercialOwner'])
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('socialConversation', fn ($q) => $q->where('contact_name', 'like', '%'.mb_substr($this->search, 0, 120).'%')->orWhere('contact_external_id', 'like', '%'.mb_substr($this->search, 0, 120).'%')))
            ->when(ctype_digit($this->program), fn (Builder $q) => $q->where(fn ($q) => $q->where('commercial_program_id', (int) $this->program)->orWhereHas('lead', fn ($q) => $q->where('program_id', (int) $this->program))))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from), fn (Builder $q) => $q->whereDate('detected_at', '>=', $this->from))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to), fn (Builder $q) => $q->whereDate('detected_at', '<=', $this->to))
            ->when($this->humanOnly, fn (Builder $q) => $q->where('commercial_hold', true)->whereNull('commercial_close_reason'))
            ->when($this->approvalOnly, fn (Builder $q) => $q->whereHas('actions', fn ($q) => $q->where('status', 'pending_approval')))
            ->when($this->consentOnly, fn (Builder $q) => $q->whereExists(function ($q): void {
                $q->selectRaw('1')->from('communication_consents')->whereColumn('communication_consents.institution_id', 'follow_up_opportunities.institution_id')->whereColumn('communication_consents.channel', 'follow_up_opportunities.provider')->where('state', 'granted')->where('purpose', 'requested_follow_up')->whereNotNull('source')->whereNotNull('evidence')->where(fn ($q) => $q->whereColumn('communication_consents.contact_id', 'follow_up_opportunities.contact_id')->orWhereColumn('communication_consents.social_conversation_id', 'follow_up_opportunities.social_conversation_id'));
            }))
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

        $selected = ctype_digit($this->selectedOpportunity) ? FollowUpOpportunity::query()->with(['channel.whatsappTemplates', 'socialConversation', 'contact', 'lead.program', 'commercialOwner'])->findOrFail((int) $this->selectedOpportunity) : null;

        $actions = $selected ? FollowUpAction::query()->where('follow_up_opportunity_id', $selected->id)->latest()->paginate(10, pageName: 'actionsPage') : collect();
        $executionPreviews = [];
        foreach ($actions as $action) {
            if ($action->draft_state !== 'review' || $action->draft_expires_at?->isPast()) {
                continue;
            }
            try {
                $executionPreviews[$action->id] = app(\Modules\FollowUp\Services\ExecutionContent::class)->build($action);
            } catch (\Throwable) {
                // No approval control for content that cannot be verified.
            }
        }

        $approvalAction = $this->approvalId ? FollowUpAction::query()->with('opportunity.socialConversation')->findOrFail($this->approvalId) : null;
        $approvalContent = null;
        $approvalDecision = null;
        if ($approvalAction !== null) {
            try {
                $approvalContent = app(\Modules\FollowUp\Services\ExecutionContent::class)->build($approvalAction);
                $approvalDecision = app(\Modules\FollowUp\Services\FollowUpPolicyEngine::class)->evaluate(new \Modules\FollowUp\Data\PolicyRequest($approvalAction->opportunity, 'schedule', $approvalAction->channel, $approvalAction->purpose, $approvalAction->scheduled_at, $approvalAction->expires_at, $approvalAction->social_whatsapp_template_id, auth()->user(), $approvalAction), false);
            } catch (\Throwable) {
                $approvalContent = null;
            }
        }

        return view('followup::livewire.opportunities', [
            'executionPreviews' => $executionPreviews,
            'opportunities' => $this->filteredQuery()->when($this->sort === 'priority', fn ($q) => $q->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END"))->orderBy(match ($this->sort) {
                'recent' => 'detected_at', 'next' => 'next_evaluation_at', default => 'score'
            }, $this->sort === 'next' ? 'asc' : 'desc')->orderByDesc('id')->paginate(25),
            'programs' => \Modules\Catalog\Models\Program::query()->orderBy('name_es')->get(['id', 'name_es']),
            'contextMessages' => $selected ? \Modules\Social\Models\SocialMessage::query()->where('social_conversation_id', $selected->social_conversation_id)->latest('id')->limit(6)->get()->reverse() : collect(),
            'approvalAction' => $approvalAction, 'approvalContent' => $approvalContent, 'approvalDecision' => $approvalDecision,
            'summary' => $summary,
            'bots' => Bot::query()->whereIn('id', $botIds)->orderBy('assistant_name')->get(['id', 'assistant_name', 'name']),
            'selected' => $selected,
            'commercialActivities' => $selected ? \Modules\Crm\Models\Activity::query()->where(fn ($q) => $q->where('idempotency_key', 'like', 'followup:'.$selected->id.':%')->orWhere('id', $selected->commercial_activity_id))->latest()->limit(10)->get()->filter(fn ($activity) => auth()->user()->can('view', $activity)) : collect(),
            'commercialOwners' => \App\Models\User::query()->where('institution_id', auth()->user()->institution_id)->get()->filter(fn ($user) => $user->isActive() && $user->canWorkCrm() && ! $user->crmReadOnly()),
            'actions' => $actions,
            'draftEvents' => $selected ? \Modules\FollowUp\Models\FollowUpEvent::query()->where('follow_up_opportunity_id', $selected->id)->latest('id')->limit(50)->get() : collect(),
            'consents' => $selected ? CommunicationConsent::query()->where($selected->contact_id ? 'contact_id' : 'social_conversation_id', $selected->contact_id ?: $selected->social_conversation_id)->latest()->get() : collect(),
        ]);
    }
}
