<?php

declare(strict_types=1);

namespace Modules\FollowUp\Data;

final readonly class EffectiveFollowUpSettings
{
    /**
     * @param  array<int,int>  $allowedDays
     * @param  array<int,string>  $allowedChannels
     */
    public function __construct(
        public bool $institutionEnabled,
        public bool $advisorEnabled,
        public string $mode,
        public int $firstFollowUpHours,
        public int $minimumIntervalHours,
        public int $maximumAttempts,
        public array $allowedDays,
        public string $businessHoursStart,
        public string $businessHoursEnd,
        public string $timezone,
        public array $allowedChannels,
        public bool $pauseOnHuman,
        public string $policyVersion,
        public int $eligibilityVersion,
    ) {}
}
