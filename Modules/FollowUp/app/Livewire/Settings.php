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

    public string $botId = '';

    public bool $advisorEnabled = false;

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
    }

    public function saveInstitution(FollowUpSettingsService $service): void
    {
        $service->saveInstitution(auth()->user(), [
            'enabled' => $this->enabled, 'mode' => $this->mode, 'first_follow_up_hours' => $this->firstFollowUpHours,
            'minimum_interval_hours' => $this->minimumIntervalHours, 'maximum_attempts' => $this->maximumAttempts,
            'allowed_days' => array_map('intval', $this->allowedDays), 'business_hours_start' => $this->businessHoursStart,
            'business_hours_end' => $this->businessHoursEnd, 'timezone' => $this->timezone,
            'allowed_channels' => $this->allowedChannels, 'policy_version' => $this->policyVersion,
        ]);
        session()->flash('followup_saved', __('Configuración institucional guardada e incompatibilidades invalidadas.'));
    }

    public function updatedBotId(): void
    {
        $setting = ctype_digit($this->botId) ? FollowUpAdvisorSetting::query()->where('bot_id', (int) $this->botId)->first() : null;
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
        $this->advisorTimezone = $setting->timezone;
        $this->advisorAllowedChannels = $setting->allowed_channels ?? [];
        $this->pauseOnHuman = $setting->pause_on_human;
    }

    public function saveAdvisor(FollowUpSettingsService $service): void
    {
        abort_unless(ctype_digit($this->botId), 422);
        $bot = Bot::query()->findOrFail((int) $this->botId);
        $service->saveAdvisor(auth()->user(), $bot, [
            'enabled' => $this->advisorEnabled, 'first_follow_up_hours' => $this->advisorFirstHours,
            'minimum_interval_hours' => $this->advisorMinimumHours, 'maximum_attempts' => $this->advisorMaximumAttempts,
            'allowed_days' => $this->advisorAllowedDays === [] ? null : array_map('intval', $this->advisorAllowedDays),
            'business_hours_start' => $this->advisorHoursStart, 'business_hours_end' => $this->advisorHoursEnd,
            'timezone' => $this->advisorTimezone, 'allowed_channels' => $this->advisorAllowedChannels === [] ? null : $this->advisorAllowedChannels,
            'pause_on_human' => $this->pauseOnHuman,
        ]);
        session()->flash('followup_saved', __('Configuración del asesor guardada.'));
    }

    public function render(): View
    {
        return view('followup::livewire.settings', ['bots' => Bot::query()->orderBy('assistant_name')->get(['id', 'assistant_name', 'name'])]);
    }
}
