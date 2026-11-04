<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services\Policies\Channels;

use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyDecision;
use Modules\FollowUp\Data\PolicyRequest;

interface ChannelPolicy
{
    public function evaluate(PolicyRequest $request, EffectiveFollowUpSettings $settings): ?PolicyDecision;
}
