<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services\Policies\Channels;

use Carbon\CarbonImmutable;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyDecision;
use Modules\FollowUp\Data\PolicyRequest;

final class EmailPolicy implements ChannelPolicy
{
    public function evaluate(PolicyRequest $request, EffectiveFollowUpSettings $settings): PolicyDecision
    {
        $at = CarbonImmutable::now();

        return new PolicyDecision('unknown', 'email_policy_not_implemented', 'El correo permanece como contrato futuro y no está habilitado.', 'follow_up.email', $settings->policyVersion, $at, null, hash('sha256', 'email|unknown|'.$settings->eligibilityVersion), ['eligibility_version' => $settings->eligibilityVersion]);
    }
}
