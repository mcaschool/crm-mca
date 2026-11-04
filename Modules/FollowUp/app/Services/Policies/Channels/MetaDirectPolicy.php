<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services\Policies\Channels;

use Carbon\CarbonImmutable;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyDecision;
use Modules\FollowUp\Data\PolicyRequest;

final class MetaDirectPolicy implements ChannelPolicy
{
    public function evaluate(PolicyRequest $request, EffectiveFollowUpSettings $settings): ?PolicyDecision
    {
        if ($request->stage === 'prepare') {
            return null;
        }
        $at = CarbonImmutable::now();

        return new PolicyDecision('unknown', 'provider_policy_unverified', 'No existe evidencia local suficiente para programar seguimiento por este canal.', 'follow_up.meta_direct', $settings->policyVersion, $at, null, hash('sha256', $request->channel.'|unknown|'.$settings->eligibilityVersion), ['eligibility_version' => $settings->eligibilityVersion]);
    }
}
