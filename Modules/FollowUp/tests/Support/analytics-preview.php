<?php

declare(strict_types=1);

// Reuse the guarded, provider-free test-only preview and its synthetic login.
require __DIR__.'/visual-preview.php';
foreach (\Modules\FollowUp\Models\FollowUpOpportunity::query()->get() as $index => $op) {
    $contact = \Modules\Crm\Models\Contact::factory()->create(['first_name' => 'Analítica · caso ficticio '.($index + 1)]);
    $lead = \Modules\Crm\Models\Lead::factory()->create(['contact_id' => $contact->id, 'status' => $index === 0 ? 'enrolled' : 'qualified']);
    $op->forceFill(['contact_id' => $contact->id, 'lead_id' => $lead->id, 'commercial_link_source' => 'manual', 'commercial_link_evidence' => 'Fixture local sintética', 'detected_at' => now()->subDays(25)])->save();
    $action = \Modules\FollowUp\Models\FollowUpAction::query()->where('follow_up_opportunity_id', $op->id)->firstOrFail();
    $action->forceFill(['created_at' => now()->subDays(21)])->save();
    if ($index < 2) {
        $message = \Modules\Social\Models\SocialMessage::factory()->create(['social_conversation_id' => $op->social_conversation_id, 'direction' => 'outbound', 'status' => 'read', 'external_message_id' => 'local-analytics-'.$op->id, 'provider_timestamp' => now()->subDays(20), 'body' => 'Evidencia ficticia; no enviada']);
        $action->forceFill(['status' => 'read', 'social_message_id' => $message->id, 'dispatch_at' => now()->subDays(20)])->save();
        if ($index === 0) {
            \Modules\Social\Models\SocialMessage::factory()->create(['social_conversation_id' => $op->social_conversation_id, 'direction' => 'inbound', 'provider_timestamp' => now()->subDays(19), 'body' => 'Respuesta ficticia']);
            \Modules\FollowUp\Models\FollowUpEvent::query()->create(['follow_up_opportunity_id' => $op->id, 'event_type' => 'commercial.synchronized', 'occurred_at' => now()->subDays(15), 'safe_data' => ['basis' => 'confirmed_crm', 'result' => 'converted', 'lead_id' => $lead->id]]);
        }
    } elseif ($index === 3) {
        $action->forceFill(['status' => 'delivery_unknown', 'dispatch_at' => now()->subDays(20), 'execution_error' => 'timeout'])->save();
    }
}
echo "\nAnalytics facts: ".app(\Modules\FollowUp\Services\FollowUpAnalyticsAggregator::class)->reconcile();
