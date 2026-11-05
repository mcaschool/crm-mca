<?php

declare(strict_types=1);

// Reuse the guarded synthetic visual fixture. Never run against operational data.
ob_start();
require __DIR__.'/visual-preview.php';
$preview = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);

use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\FollowUpDraftContext;
use Modules\FollowUp\Services\WhatsAppCadence;
use Modules\Social\Models\SocialMessage;

FollowUpSetting::query()->firstOrFail()->update(['whatsapp_cadence' => array_replace(WhatsAppCadence::DEFAULTS, ['enabled' => true])]);
foreach ($preview['opportunities'] as $index => $id) {
    $opportunity = FollowUpOpportunity::query()->findOrFail($id);
    $anchor = now()->subHours($index === 3 ? 26 : 6);
    SocialMessage::query()->where('social_conversation_id', $opportunity->social_conversation_id)->where('direction', 'inbound')->update(['provider_timestamp' => $anchor]);
    SocialMessage::query()->whereKey($opportunity->source_last_message_id)->update(['provider_timestamp' => $anchor->copy()->addMinute()]);
    $opportunity->update(['last_inbound_at' => $anchor, 'last_outbound_at' => $anchor->copy()->addMinute(), 'source_last_message_at' => $anchor->copy()->addMinute()]);
    $inbound = SocialMessage::query()->where('social_conversation_id', $opportunity->social_conversation_id)->where('direction', 'inbound')->firstOrFail();
    foreach (FollowUpAction::query()->where('follow_up_opportunity_id', $id)->get() as $action) {
        $action->forceFill(['scheduled_at' => now(), 'cadence_slot' => 1, 'cadence_anchor_id' => $inbound->id, 'cadence_key' => app(WhatsAppCadence::class)->key($opportunity, $inbound->id, 1)])->save();
        if ($action->draft_state === 'review') {
            $action->forceFill(['context_fingerprint' => app(FollowUpDraftContext::class)->build($action->fresh())['fingerprint']])->save();
        }
    }
}
echo json_encode($preview, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
