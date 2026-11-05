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

        $margin = (int) ($settings->cadence['margin'] ?? 60);
        $window = app(\Modules\FollowUp\Services\WhatsAppWindow::class)->inspect((int) $opportunity->social_conversation_id, $margin);
        $at = $request->stage === 'send' ? now() : $request->scheduledAt;
        $withinWindow = $window['state'] === 'open' && $at !== null && $window['expires_at'] !== null
            && $at->lt($window['expires_at']->subMinutes($margin));
        if ($withinWindow && $request->whatsappTemplateId === null) {
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
