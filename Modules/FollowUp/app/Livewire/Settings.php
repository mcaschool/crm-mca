<?php

declare(strict_types=1);

namespace Modules\FollowUp\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Services\FollowUpSettingsResolver;
use Modules\FollowUp\Services\FollowUpSettingsService;
use Modules\Institutions\Models\Bot;

#[Layout('layouts.app')]
class Settings extends Component
{
    public function boot(): void
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->canManageSettings(), 403);
    }

    public function stopExecution(\Modules\FollowUp\Services\ExecutionSwitches $switches): void
    {
        $switches->set(auth()->user(), 'institution', (int) auth()->user()->institution_id, false);
        session()->flash('followup_saved', 'Se detuvieron los nuevos envíos de la institución. Los ya despachados conservan su conciliación.');
    }

    /** @var array<string,mixed> */
    public array $cadence = \Modules\FollowUp\Services\WhatsAppCadence::DEFAULTS;

    /** @var array<string,mixed> */
    public array $advisorCadence = \Modules\FollowUp\Services\WhatsAppCadence::DEFAULTS;

    public bool $enabled = false;

    public string $mode = 'manual';

    public int $firstFollowUpHours = 24;

    public int $minimumIntervalHours = 24;

    public int $maximumAttempts = 3;

    /** @var array<int,int> */
    public array $allowedDays = [1, 2, 3, 4, 5];

    public string $businessHoursStart = '09:00';

    public string $businessHoursEnd = '18:00';

    public string $timezone = 'UTC';

    /** @var array<int,string> */
    public array $allowedChannels = ['whatsapp'];

    public string $policyVersion = '2.0';

    #[\Livewire\Attributes\Url(as: 'asesor')]
    public string $botId = '';

    public bool $advisorEnabled = false;

    public ?string $advisorMode = null;

    /** @var array<int,int> */
    public array $advisorChannelIds = [];

    public ?int $advisorFirstHours = null;

    public ?int $advisorMinimumHours = null;

    public ?int $advisorMaximumAttempts = null;

    /** @var array<int,int> */
    public array $advisorAllowedDays = [];

    public ?string $advisorHoursStart = null;

    public ?string $advisorHoursEnd = null;

    public ?string $advisorTimezone = null;

    /** @var array<int,string> */
    public array $advisorAllowedChannels = [];

    public bool $pauseOnHuman = true;

    public function mount(FollowUpSettingsResolver $resolver): void
    {
        abort_unless(auth()->user()?->canManageSettings(), 403);
        $setting = $resolver->institution((int) auth()->user()->institution_id);
        $this->cadence = array_replace(\Modules\FollowUp\Services\WhatsAppCadence::DEFAULTS, $setting->whatsapp_cadence ?? []);
        $this->enabled = (bool) $setting->enabled;
        $this->mode = $setting->mode;
        $this->firstFollowUpHours = (int) $setting->first_follow_up_hours;
        $this->minimumIntervalHours = (int) $setting->minimum_interval_hours;
        $this->maximumAttempts = (int) $setting->maximum_attempts;
        $this->allowedDays = (array) $setting->allowed_days;
        $this->businessHoursStart = substr((string) $setting->business_hours_start, 0, 5);
        $this->businessHoursEnd = substr((string) $setting->business_hours_end, 0, 5);
        $this->timezone = $setting->timezone;
        $this->allowedChannels = (array) $setting->allowed_channels;
        $this->policyVersion = $setting->policy_version;
        if ($this->botId !== '') {
            $this->updatedBotId();
        }
    }

    public function saveInstitution(FollowUpSettingsService $service): void
    {
        $service->saveInstitution(auth()->user(), [
            'whatsapp_cadence' => $this->cadence, 'enabled' => $this->enabled, 'mode' => $this->mode, 'first_follow_up_hours' => $this->firstFollowUpHours,
            'minimum_interval_hours' => $this->minimumIntervalHours, 'maximum_attempts' => $this->maximumAttempts,
            'allowed_days' => array_map('intval', $this->allowedDays), 'business_hours_start' => $this->businessHoursStart,
            'business_hours_end' => $this->businessHoursEnd, 'timezone' => $this->timezone,
            'allowed_channels' => $this->allowedChannels, 'policy_version' => $this->policyVersion,
        ]);
        session()->flash('followup_saved', __('Configuración institucional guardada e incompatibilidades invalidadas.'));
    }

    public function updatedBotId(): void
    {
        $this->resetValidation();
        if ($this->botId !== '') {
            abort_unless(ctype_digit($this->botId), 404);
            Bot::query()->findOrFail((int) $this->botId);
        }
        $setting = ctype_digit($this->botId) ? FollowUpAdvisorSetting::query()->where('bot_id', (int) $this->botId)->first() : null;
        $institutionChannels = app(FollowUpSettingsResolver::class)->institution((int) auth()->user()->institution_id)->allowed_channels;
        $inheritedChannels = array_intersect($institutionChannels, $setting->allowed_channels ?? $institutionChannels);
        $this->advisorCadence = array_replace(\Modules\FollowUp\Services\WhatsAppCadence::DEFAULTS, $setting->whatsapp_cadence ?? app(FollowUpSettingsResolver::class)->institution((int) auth()->user()->institution_id)->whatsapp_cadence ?? []);
        $this->advisorMode = $setting?->mode;
        $this->advisorChannelIds = $setting->allowed_channel_ids ?? \Modules\Social\Models\SocialChannel::query()
            ->where('advisor_bot_id', (int) $this->botId)->where('provider', 'whatsapp')->get()
            ->filter(fn ($channel) => in_array($channel->provider, $inheritedChannels, true) && $channel->advisor_enabled && $channel->automationCanSend())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($setting === null) {
            $this->advisorEnabled = false;
            $this->advisorFirstHours = null;
            $this->advisorMinimumHours = null;
            $this->advisorMaximumAttempts = null;
            $this->advisorAllowedDays = [];
            $this->advisorHoursStart = null;
            $this->advisorHoursEnd = null;
            $this->advisorTimezone = null;
            $this->advisorAllowedChannels = [];
            $this->pauseOnHuman = true;

            return;
        }
        $this->advisorEnabled = $setting->enabled;
        $this->advisorFirstHours = $setting->first_follow_up_hours;
        $this->advisorMinimumHours = $setting->minimum_interval_hours;
        $this->advisorMaximumAttempts = $setting->maximum_attempts;
        $this->advisorAllowedDays = $setting->allowed_days ?? [];
        $this->advisorHoursStart = $setting->business_hours_start ? substr($setting->business_hours_start, 0, 5) : null;
        $this->advisorHoursEnd = $setting->business_hours_end ? substr($setting->business_hours_end, 0, 5) : null;
        $this->advisorTimezone = null;
        // The explicit channel matrix replaces the legacy provider-only selection.
        $this->advisorAllowedChannels = [];
        $this->pauseOnHuman = $setting->pause_on_human;
    }

    public function saveAdvisor(FollowUpSettingsService $service): void
    {
        $this->resetValidation();
        abort_unless(ctype_digit($this->botId), 422);
        $bot = Bot::query()->findOrFail((int) $this->botId);
        $service->saveAdvisor(auth()->user(), $bot, [
            'whatsapp_cadence' => $this->advisorCadence, 'enabled' => $this->advisorEnabled, 'mode' => $this->advisorMode ?: null,
            'allowed_channel_ids' => array_map('intval', $this->advisorChannelIds), 'first_follow_up_hours' => $this->advisorFirstHours,
            'minimum_interval_hours' => $this->advisorMinimumHours, 'maximum_attempts' => $this->advisorMaximumAttempts,
            'allowed_days' => $this->advisorAllowedDays === [] ? null : array_map('intval', $this->advisorAllowedDays),
            'business_hours_start' => $this->advisorHoursStart, 'business_hours_end' => $this->advisorHoursEnd,
            'timezone' => $this->advisorTimezone ?: null, 'allowed_channels' => $this->advisorAllowedChannels === [] ? null : $this->advisorAllowedChannels,
            'pause_on_human' => $this->pauseOnHuman,
        ]);
        session()->flash('followup_saved', __('Configuración del asesor guardada.'));
    }

    public function render(): View
    {
        $bots = Bot::query()->orderBy('assistant_name')->get(['id', 'institution_id', 'assistant_name', 'name', 'avatar_path', 'status']);
        $selectedBot = $bots->firstWhere('id', (int) $this->botId);
        $institution = app(FollowUpSettingsResolver::class)->institution((int) auth()->user()->institution_id);

        return view('followup::livewire.settings', [
            'bots' => $bots,
            'selectedBot' => $selectedBot,
            'institutionSetting' => $institution,
            'advisorSettings' => FollowUpAdvisorSetting::query()->get()->keyBy('bot_id'),
            'channels' => \Modules\Social\Models\SocialChannel::query()->with('advisorBot:id,assistant_name,name')->orderBy('display_name')->get(),
            'effective' => $selectedBot ? app(FollowUpSettingsResolver::class)->effective((int) auth()->user()->institution_id, (int) $selectedBot->id) : null,
            'switches' => \Illuminate\Support\Facades\DB::table('follow_up_execution_switches')->where(fn ($q) => $q->where('institution_id', auth()->user()->institution_id)->orWhere(fn ($q) => $q->where('scope', 'global')->where('subject_id', 0)))->orderBy('scope')->get(['scope', 'subject_id', 'enabled', 'verified_until', 'daily_limit']),
            'queuedCount' => \Modules\FollowUp\Models\FollowUpAction::query()->whereIn('draft_state', ['queued', 'generating'])->count(),
            'errorCount' => \Modules\FollowUp\Models\FollowUpAction::query()->where(fn ($q) => $q->where('draft_state', 'error')->orWhereIn('status', ['failed', 'delivery_unknown']))->count(),
        ]);
    }
}
