<?php

declare(strict_types=1);

namespace Modules\FollowUp\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Institutions\Models\Bot;

#[Layout('layouts.app')]
class Workspace extends Component
{
    use WithPagination;

    #[Url(as: 'vista')]
    public string $section = 'summary';

    #[Url(as: 'buscar')]
    public string $search = '';

    #[Url(as: 'estado')]
    public string $status = '';

    #[Url(as: 'canal')]
    public string $channel = '';

    #[Url(as: 'asesor')]
    public string $bot = '';

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    #[Url(as: 'oportunidad')]
    public string $opportunity = '';

    #[Url(as: 'evento')]
    public string $eventType = '';

    #[Url(as: 'actor')]
    public string $actor = '';

    #[Url(as: 'aprobacion')]
    public bool $approvalOnly = false;

    #[Url(as: 'bloqueadas')]
    public bool $blockedOnly = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->canManageSettings(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'channel', 'bot', 'from', 'to', 'opportunity', 'eventType', 'actor', 'approvalOnly', 'blockedOnly']);
        $this->resetPage();
    }

    public function render(): View
    {
        abort_unless(in_array($this->section, ['summary', 'actions', 'drafts', 'human', 'history'], true), 404);
        $opportunities = FollowUpOpportunity::query()->with(['socialConversation', 'bot', 'lead.program', 'commercialOwner', 'commercialActivity']);
        $actions = FollowUpAction::query()->with(['opportunity.socialConversation', 'opportunity.bot']);
        $events = FollowUpEvent::query()->with('actor');
        if ($this->actor === 'system') {
            $events->whereNull('actor_user_id');
        } elseif ($this->actor !== '') {
            $events->whereHas('actor', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->actor, 0, 120).'%'));
        }
        if ($this->search !== '') {
            $term = '%'.mb_substr($this->search, 0, 120).'%';
            $opportunities->whereHas('socialConversation', fn ($q) => $q->where('contact_name', 'like', $term)->orWhere('contact_external_id', 'like', $term));
            $actions->whereHas('opportunity.socialConversation', fn ($q) => $q->where('contact_name', 'like', $term)->orWhere('contact_external_id', 'like', $term));
        }
        if (in_array($this->channel, ['whatsapp', 'instagram', 'messenger'], true)) {
            $actions->where('channel', $this->channel);
            $opportunities->where('provider', $this->channel);
        }
        if (ctype_digit($this->bot)) {
            $actions->whereHas('opportunity', fn ($q) => $q->where('bot_id', (int) $this->bot));
            $opportunities->where('bot_id', (int) $this->bot);
        }
        if (ctype_digit($this->opportunity)) {
            $actions->where('follow_up_opportunity_id', (int) $this->opportunity);
            $events->where('follow_up_opportunity_id', (int) $this->opportunity);
            $opportunities->whereKey((int) $this->opportunity);
        }
        foreach ([[$this->from, '>='], [$this->to, '<=']] as [$date, $operator]) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                $actions->whereDate('scheduled_at', $operator, $date);
                $events->whereDate('occurred_at', $operator, $date);
                $opportunities->whereDate('commercial_updated_at', $operator, $date);
            }
        }
        if ($this->status !== '') {
            $actions->where($this->section === 'drafts' ? 'draft_state' : 'status', $this->status);
            $opportunities->where('status', $this->status);
        }
        if ($this->approvalOnly) {
            $actions->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->where(fn ($q) => $q->where('status', 'pending_approval')->orWhere(fn ($q) => $q->where('draft_state', 'review')->whereNull('approved_at')));
        }
        if ($this->blockedOnly) {
            $actions->where(fn ($q) => $q->where('status', 'suppressed')->orWhere(fn ($q) => $q->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->where(fn ($q) => $q->whereNotNull('block_reason')->orWhere('policy_status', '!=', 'allowed'))));
        }
        if ($this->eventType !== '') {
            $events->where('event_type', mb_substr($this->eventType, 0, 48));
        }
        $counts = [];
        if ($this->section === 'summary') {
            $counts = [
                'active' => FollowUpOpportunity::query()->where('status', 'open')->count(),
                'high' => FollowUpOpportunity::query()->whereIn('status', ['open', 'paused'])->where('priority', 'high')->count(),
                'approval' => FollowUpAction::query()->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->where(fn ($q) => $q->where('status', 'pending_approval')->orWhere(fn ($q) => $q->where('draft_state', 'review')->whereNull('approved_at')))->count(),
                'scheduled' => FollowUpAction::query()->where('status', 'scheduled')->where('policy_status', 'allowed')->whereNull('block_reason')->where('scheduled_at', '>=', now())->where('expires_at', '>', now())->count(),
                'human' => FollowUpOpportunity::query()->where('commercial_hold', true)->whereNull('commercial_close_reason')->count(),
                'blocked' => FollowUpAction::query()->where(fn ($q) => $q->where('status', 'suppressed')->orWhere(fn ($q) => $q->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->where(fn ($q) => $q->whereNotNull('block_reason')->orWhere('policy_status', '!=', 'allowed'))))->count(),
            ];
        }

        return view('followup::livewire.workspace', [
            'counts' => $counts,
            'rows' => match ($this->section) {
                'actions' => $actions->latest('id')->paginate(20),
                'drafts' => $actions->whereNotIn('draft_state', ['none', 'empty'])->latest('id')->paginate(20),
                'human' => $opportunities->where('commercial_hold', true)->whereNull('commercial_close_reason')->orderByDesc('score')->paginate(20),
                'history' => $events->latest('id')->paginate(30),
                default => null,
            },
            'recent' => $this->section === 'summary' ? $opportunities->latest('detected_at')->limit(5)->get() : collect(),
            'upcoming' => $this->section === 'summary' ? $actions->whereIn('status', ['scheduled', 'pending_approval', 'failed', 'delivery_unknown'])->orderBy('scheduled_at')->limit(5)->get() : collect(),
            'bots' => Bot::query()->orderBy('assistant_name')->get(['id', 'assistant_name', 'name']),
        ]);
    }
}
