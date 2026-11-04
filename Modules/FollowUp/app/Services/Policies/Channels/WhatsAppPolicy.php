<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services\Policies\Channels;

use Carbon\CarbonImmutable;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyDecision;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\Social\Models\SocialWhatsAppTemplate;

final class WhatsAppPolicy implements ChannelPolicy
{
    public function evaluate(PolicyRequest $request, EffectiveFollowUpSettings $settings): ?PolicyDecision
    {
        $opportunity = $request->opportunity;
        $channel = $opportunity->channel;
        if ($channel === null || $channel->provider !== 'whatsapp') {
            return $this->decision('denied', 'channel_mismatch', 'El canal no corresponde a WhatsApp.', $settings);
        }
        if (! $channel->automationCanSend()) {
            return $this->decision('denied', 'channel_unavailable', 'El canal no está operativo para una futura acción.', $settings);
        }
        if ($request->stage === 'prepare') {
            return null;
        }

        $lastInbound = $request->stage === 'send'
            ? \Modules\Social\Models\SocialMessage::query()->where('social_conversation_id', $opportunity->social_conversation_id)->where('direction', 'inbound')->latest('provider_timestamp')->first()?->provider_timestamp
            : $opportunity->last_inbound_at;
        $withinWindow = $lastInbound !== null && $lastInbound->gt(now()->subHours(24)) && $lastInbound->lte(now());
        if ($withinWindow) {
            return null;
        }
        if ($request->whatsappTemplateId === null) {
            return $this->decision('denied', 'whatsapp_template_required', 'Fuera de la ventana de atención se requiere una plantilla aprobada.', $settings);
        }

        $template = SocialWhatsAppTemplate::query()->whereKey($request->whatsappTemplateId)->where('social_channel_id', $channel->id)->first();
        if ($template === null || ! $template->isApproved()) {
            return $this->decision('denied', 'whatsapp_template_not_approved', 'La plantilla no está aprobada para este canal.', $settings);
        }
        if ($request->purpose !== 'operational' && $template->category !== 'MARKETING') {
            return $this->decision('denied', 'whatsapp_template_category_mismatch', 'La categoría de la plantilla no coincide con la finalidad comercial.', $settings);
        }

        return null;
    }

    private function decision(string $status, string $code, string $explanation, EffectiveFollowUpSettings $settings): PolicyDecision
    {
        $at = CarbonImmutable::now();

        return new PolicyDecision($status, $code, $explanation, 'follow_up.whatsapp', $settings->policyVersion, $at, null, hash('sha256', $status.'|'.$code.'|'.$settings->eligibilityVersion), ['eligibility_version' => $settings->eligibilityVersion]);
    }
}
