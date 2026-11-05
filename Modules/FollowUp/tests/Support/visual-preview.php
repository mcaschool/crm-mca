<?php

declare(strict_types=1);

// Disposable fixtures only. Run after the automated suites, never against the
// application's operational database. No provider or queue worker is invoked.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'crm_mca_test') {
    throw new RuntimeException('Only APP_ENV=testing and crm_mca_test are permitted.');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Mail::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.policy_enabled' => true, 'followup.execution_enabled' => false, 'social.advisor.autoreply_enabled' => false]);
$institution = \Modules\Institutions\Models\Institution::factory()->create(['name' => 'Pruebas visuales · Seguimiento']);
app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($institution->id);
$email = 'followup-'.bin2hex(random_bytes(3)).'@example.test';
$user = \App\Models\User::factory()->create(['institution_id' => $institution->id, 'name' => 'Administración de prueba', 'email' => $email, 'password' => \Illuminate\Support\Facades\Hash::make('LocalStage6!Review2026'), 'role' => 'admin', 'status' => 'active']);
auth()->setUser($user);
$bot = \Modules\Institutions\Models\Bot::factory()->create(['name' => 'Admisiones', 'assistant_name' => 'Sofía']);
$channel = \Modules\Social\Models\SocialChannel::factory()->create(['display_name' => 'Admisiones · WhatsApp ficticio', 'provider' => 'whatsapp', 'advisor_bot_id' => $bot->id, 'advisor_enabled' => true, 'is_active' => true, 'connection_status' => 'connected_cloud_api']);
$otherBot = \Modules\Institutions\Models\Bot::factory()->create(['name' => 'Orientación', 'assistant_name' => 'Daniel']);
\Modules\Institutions\Models\Bot::factory()->create(['name' => 'Archivo', 'assistant_name' => 'Asesor inactivo', 'status' => 'inactive']);
foreach ([
    ['Coexistence ficticio', 'whatsapp', 'connected_coexistence', $bot->id],
    ['Instagram ficticio', 'instagram', 'connected_cloud_api', $bot->id],
    ['Messenger ficticio', 'messenger', 'connected_cloud_api', $bot->id],
    ['WhatsApp desconectado ficticio', 'whatsapp', 'disconnected', $bot->id],
    ['Orientación · WhatsApp ficticio', 'whatsapp', 'connected_cloud_api', $otherBot->id],
] as [$name, $provider, $connection, $advisor]) {
    \Modules\Social\Models\SocialChannel::factory()->create(['display_name' => $name, 'provider' => $provider, 'advisor_bot_id' => $advisor, 'advisor_enabled' => true, 'is_active' => true, 'connection_status' => $connection]);
}
\Modules\FollowUp\Models\FollowUpSetting::query()->create(['enabled' => true, 'mode' => 'supervised', 'first_follow_up_hours' => 1, 'minimum_interval_hours' => 1, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'business_hours_start' => '00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC', 'allowed_channels' => ['whatsapp']]);
\Modules\FollowUp\Models\FollowUpAdvisorSetting::query()->create(['bot_id' => $bot->id, 'enabled' => true]);
$ids = [];
foreach (['Elena · caso ficticio', 'Diego · caso ficticio', 'Andrea · caso ficticio', 'Lucía · caso ficticio'] as $index => $name) {
    $conversation = \Modules\Social\Models\SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'whatsapp', 'contact_name' => $name, 'contact_external_id' => '1555000000'.($index + 1), 'contact_avatar_url' => null, 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    \Modules\Social\Models\SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'inbound', 'body' => 'Me gustaría conocer el programa y aclarar algunas dudas.', 'provider_timestamp' => now()->subHours(3)]);
    $last = \Modules\Social\Models\SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'body' => '¿Sobre qué aspecto necesitas información?', 'provider_timestamp' => now()->subHours(2)]);
    $op = \Modules\FollowUp\Models\FollowUpOpportunity::factory()->create(['bot_id' => $bot->id, 'social_channel_id' => $channel->id, 'social_conversation_id' => $conversation->id, 'source_last_message_id' => $last->id, 'source_last_message_at' => now()->subHours(2), 'last_inbound_at' => now()->subHours(3), 'last_outbound_at' => now()->subHours(2), 'priority' => $index === 0 ? 'high' : 'medium']);
    app(\Modules\FollowUp\Services\ConsentService::class)->record($user, null, $conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'Formulario ficticio', 'Evidencia simulada para revisión visual local');
    $cycle = \Modules\FollowUp\Models\FollowUpCycle::query()->create(['follow_up_opportunity_id' => $op->id, 'sequence' => 1, 'classification' => 'general_interest', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $action = \Modules\FollowUp\Models\FollowUpAction::query()->create(['follow_up_opportunity_id' => $op->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => $index === 1 ? 'delivery_unknown' : 'pending_approval', 'policy_status' => 'allowed', 'policy_reason_code' => 'schedule_allowed', 'policy_version' => '2.0', 'eligibility_version' => 2, 'idempotency_key' => hash('sha256', 'visual'.$op->id), 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'scheduled_at' => now()->addHour(), 'expires_at' => now()->addHours(12)]);
    if ($index === 2) {
        $current = $op->fresh();
        app(\Modules\FollowUp\Services\FollowUpCommercialCoordinator::class)->createTask($user, $op->id, $current->commercial_version, $user->id, 'human_review', now()->addDays(2)->toDateTimeString());
    } else {
        $action->forceFill(['draft_state' => 'review', 'draft_version' => 1, 'draft_generated_at' => now(), 'draft_expires_at' => now()->addHours(10), 'draft_payload' => ['draft_text' => 'Hola, ¿qué información te gustaría revisar?', 'commercial_objective' => 'Aclarar dudas del programa', 'context_summary' => 'La persona pidió información y no ha respondido al último intercambio.', 'recommended_action' => 'draft_message', 'language' => 'es', 'source_references' => [], 'warnings' => ['Contenido ficticio: solo para revisión local.'], 'reason_codes' => ['pending_question'], 'requires_human_review' => true]])->save();
        $action->forceFill(['context_fingerprint' => app(\Modules\FollowUp\Services\FollowUpDraftContext::class)->build($action)['fingerprint']])->save();
    }
    $ids[] = $op->id;
}
echo json_encode(['email' => $email, 'opportunities' => $ids, 'institution_id' => $institution->id], JSON_PRETTY_PRINT);
