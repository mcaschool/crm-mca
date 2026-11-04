<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\Institutions\Models\Institution;

final class FollowUpSettingsResolver
{
    public function institution(int $institutionId): FollowUpSetting
    {
        $existing = FollowUpSetting::query()->where('institution_id', $institutionId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $institution = Institution::query()->find($institutionId);
        $timezone = $institution === null ? 'UTC' : $institution->timezone;

        return new FollowUpSetting(['institution_id' => $institutionId, 'allowed_days' => [1, 2, 3, 4, 5], 'allowed_channels' => ['whatsapp'], 'timezone' => $timezone]);
    }

    public function effective(int $institutionId, int $botId): EffectiveFollowUpSettings
    {
        $institution = $this->institution($institutionId);
        $advisor = FollowUpAdvisorSetting::query()->where('bot_id', $botId)->first();

        if ($advisor === null) {
            return new EffectiveFollowUpSettings(
                institutionEnabled: $institution->enabled,
                advisorEnabled: false,
                mode: $institution->mode,
                firstFollowUpHours: $institution->first_follow_up_hours,
                minimumIntervalHours: $institution->minimum_interval_hours,
                maximumAttempts: $institution->maximum_attempts,
                allowedDays: $institution->allowed_days,
                businessHoursStart: $institution->business_hours_start,
                businessHoursEnd: $institution->business_hours_end,
                timezone: $institution->timezone,
                allowedChannels: $institution->allowed_channels,
                pauseOnHuman: true,
                policyVersion: $institution->policy_version,
                eligibilityVersion: $institution->lock_version,
            );
        }

        return new EffectiveFollowUpSettings(
            institutionEnabled: $institution->enabled,
            advisorEnabled: $advisor->enabled,
            mode: $institution->mode,
            firstFollowUpHours: max($institution->first_follow_up_hours, $advisor->first_follow_up_hours ?? 0),
            minimumIntervalHours: max($institution->minimum_interval_hours, $advisor->minimum_interval_hours ?? 0),
            maximumAttempts: min($institution->maximum_attempts, $advisor->maximum_attempts ?? $institution->maximum_attempts),
            allowedDays: array_values(array_intersect($institution->allowed_days, $advisor->allowed_days ?? $institution->allowed_days)),
            businessHoursStart: max($institution->business_hours_start, $advisor->business_hours_start ?? $institution->business_hours_start),
            businessHoursEnd: min($institution->business_hours_end, $advisor->business_hours_end ?? $institution->business_hours_end),
            timezone: $advisor->timezone ?? $institution->timezone,
            allowedChannels: array_values(array_intersect($institution->allowed_channels, $advisor->allowed_channels ?? $institution->allowed_channels)),
            pauseOnHuman: $advisor->pause_on_human,
            policyVersion: $institution->policy_version,
            eligibilityVersion: $institution->lock_version + $advisor->lock_version,
        );
    }
}
