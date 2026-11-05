<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\FollowUpActionPlanner;
use Modules\FollowUp\Services\FollowUpPolicyEngine;
use Modules\FollowUp\Services\FollowUpSettingsResolver;
use Modules\FollowUp\Services\FollowUpSettingsService;
use Modules\FollowUp\Services\OpportunityDetector;
use Modules\FollowUp\Services\WhatsAppCadence;
use Modules\FollowUp\Services\WhatsAppWindow;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Queue::fake();
    config(['followup.policy_enabled' => true, 'followup.execution_enabled' => false, 'followup.detection_enabled' => true, 'social.advisor.autoreply_enabled' => false]);
    $institution = Institution::factory()->create(['timezone' => 'UTC']);
    app(CurrentInstitution::class)->set($institution->id);
    $this->admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->create();
    $this->channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id, 'advisor_enabled' => true, 'connection_status' => 'connected_cloud_api']);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $this->channel->id, 'provider' => 'whatsapp', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open', 'last_message_at' => now()->subHours(5)]);
    $this->inbound = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'body' => 'Quiero información del programa', 'provider_timestamp' => now()->subHours(5)]);
    $this->outbound = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'provider_timestamp' => now()->subHours(5)->addMinute()]);
    $this->op = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $this->channel->id, 'social_conversation_id' => $this->conversation->id, 'provider' => 'whatsapp', 'status' => 'open', 'contact_id' => null, 'lead_id' => null, 'source_last_message_id' => $this->outbound->id, 'source_last_message_at' => $this->outbound->provider_timestamp, 'last_inbound_at' => $this->inbound->provider_timestamp, 'last_outbound_at' => $this->outbound->provider_timestamp]);
    $this->cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->op->id, 'sequence' => 1, 'classification' => 'general', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $this->setting = FollowUpSetting::query()->create(['enabled' => true, 'mode' => 'supervised', 'first_follow_up_hours' => 24, 'minimum_interval_hours' => 24, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'business_hours_start' => '00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC', 'allowed_channels' => ['whatsapp'], 'whatsapp_cadence' => array_replace(WhatsAppCadence::DEFAULTS, ['enabled' => true])]);
    $this->advisor = FollowUpAdvisorSetting::query()->create(['bot_id' => $this->bot->id, 'enabled' => true]);
    app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'test', 'synthetic opt in');
});

function cadencePropose($test, int $hours = 0): FollowUpAction
{
    return app(FollowUpActionPlanner::class)->propose($test->admin, $test->op->id, 'whatsapp', 'requested_follow_up', now()->addHours($hours), now()->addDays(2));
}

it('derives the UTC window only from customer provider time', function (int $age, string $state, int $remaining) {
    $this->inbound->update(['provider_timestamp' => now()->subMinutes($age)]);
    $result = app(WhatsAppWindow::class)->inspect($this->conversation->id);
    expect($result['state'])->toBe($state)->and($result['remaining_minutes'])->toBe($remaining);
})->with([[0, 'open', 1440], [1200, 'open', 240], [1410, 'closing', 30], [1440, 'expired', 0], [1500, 'expired', 0]]);

it('blocks unverified or future timestamps without using receipt time', function (string $case) {
    $this->inbound->forceFill(match ($case) {
        'missing' => ['provider_timestamp' => null],
        'future' => ['provider_timestamp' => now()->addMinute()],
        default => ['ai_meta' => null],
    })->save();
    expect(app(WhatsAppWindow::class)->inspect($this->conversation->id)['state'])->toBe('unknown');
    expect(cadencePropose($this)->status)->toBe('suppressed');
})->with(['missing', 'future', 'unverified']);

it('never extends the window from an outbound app echo or a delayed old inbound', function () {
    $before = app(WhatsAppWindow::class)->inspect($this->conversation->id);
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'app', 'provider_timestamp' => now()]);
    $old = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()->subDays(2)]);
    app(OpportunityDetector::class)->recordProspectResponse($this->conversation->id, $old->id);
    expect(app(WhatsAppWindow::class)->inspect($this->conversation->id)['expires_at']->equalTo($before['expires_at']))->toBeTrue()
        ->and($this->op->fresh()->last_inbound_at->equalTo($this->inbound->provider_timestamp))->toBeTrue();
});

it('reserves one persistent slot and treats double clicks as the same proposal', function () {
    $first = cadencePropose($this);
    expect($first->status)->toBe('pending_approval')->and($first->cadence_slot)->toBe(1)
        ->and(cadencePropose($this)->id)->toBe($first->id);
    $second = app(FollowUpActionPlanner::class)->propose($this->admin, $this->op->id, 'whatsapp', 'requested_follow_up', now()->addMinute(), now()->addDay());
    expect($second->status)->toBe('suppressed')->and($second->block_reason)->toBe('cadence_slot_used');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('uses moments 1 2 and 3 and does not compress or resend cancelled slots', function () {
    $one = cadencePropose($this);
    $one->forceFill(['status' => 'sent', 'dispatch_at' => now()])->save();
    $this->travel(9)->hours();
    $two = cadencePropose($this);
    expect($two->cadence_slot)->toBe(2);
    app(FollowUpActionPlanner::class)->cancel($this->admin, $two->id);
    $retry = app(FollowUpActionPlanner::class)->propose($this->admin, $this->op->id, 'whatsapp', 'requested_follow_up', now()->addMinute(), now()->addDay());
    expect($retry->status)->toBe('suppressed');
    $this->travel(7)->hours();
    $three = cadencePropose($this);
    expect($three->cadence_slot)->toBe(3);
    $summary = app(WhatsAppCadence::class)->summary($this->op);
    expect($summary['sent'])->toBe(1)->and($summary['reserved'])->toBe(1);
});

it('skips missed moments and blocks near closing and future expired schedules', function () {
    expect(cadencePropose($this, 16)->cadence_slot)->toBe(3);
    expect(cadencePropose($this, 18)->block_reason)->toBe('cadence_window_closing');
    expect(cadencePropose($this, 20)->status)->toBe('suppressed');
});

it('does not reset the lifetime count when the customer responds', function () {
    $one = cadencePropose($this);
    $one->forceFill(['status' => 'sent', 'dispatch_at' => now()])->save();
    $this->setting->update(['maximum_attempts' => 1]);
    $reply = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()]);
    app(OpportunityDetector::class)->recordProspectResponse($this->conversation->id, $reply->id);
    expect($this->op->fresh()->status)->toBe('cancelled');
    $this->op->refresh()->update(['status' => 'open', 'source_last_message_id' => $reply->id]);
    $this->travel(5)->hours();
    expect(cadencePropose($this)->block_reason)->toBe('recipient_attempt_limit');
});

it('cancels pending actions and makes old jobs ineligible after a response', function () {
    $action = cadencePropose($this);
    $reply = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()]);
    app(OpportunityDetector::class)->recordProspectResponse($this->conversation->id, $reply->id);
    expect($action->fresh()->status)->toBe('cancelled');
    app(\Modules\FollowUp\Services\FollowUpExecutor::class)->execute($action->id);
    Http::assertNothingSent();
});

it('retains consent human channel and business hours restrictions', function (string $condition, string $reason) {
    match ($condition) {
        'consent' => \Modules\FollowUp\Models\CommunicationConsent::query()->update(['state' => 'withdrawn']),
        'human' => $this->conversation->update(['automation_state' => 'human']),
        'channel' => $this->channel->update(['is_active' => false]),
        'hours' => $this->setting->update(['business_hours_start' => '15:00', 'business_hours_end' => '17:00']),
    };
    expect(cadencePropose($this)->block_reason)->toBe($reason);
})->with([['consent', 'consent_withdrawn'], ['human', 'human_intervention'], ['channel', 'channel_unavailable'], ['hours', 'outside_business_hours']]);

it('uses a conservative minimum and blocks ambiguous delivery', function () {
    $action = cadencePropose($this);
    $action->forceFill(['status' => 'delivery_unknown', 'dispatch_at' => now()])->save();
    expect(cadencePropose($this, 9)->block_reason)->toBe('recipient_execution_unresolved');
    $action->forceFill(['status' => 'sent'])->save();
    $this->travel(2)->hours();
    expect(cadencePropose($this)->status)->toBe('suppressed');
});

it('persists advisor cadence and rejects weaker settings', function () {
    $c = array_replace(WhatsAppCadence::DEFAULTS, ['enabled' => true, 'first' => 6, 'maximum' => 2]);
    app(FollowUpSettingsService::class)->saveAdvisor($this->admin, $this->bot, ['enabled' => true, 'pause_on_human' => true, 'whatsapp_cadence' => $c]);
    expect(app(FollowUpSettingsResolver::class)->effective($this->admin->institution_id, $this->bot->id)->cadence['first'])->toBe(6);
    $c['minimum'] = 2;
    expect(fn () => app(FollowUpSettingsService::class)->saveAdvisor($this->admin, $this->bot, ['enabled' => true, 'pause_on_human' => true, 'whatsapp_cadence' => $c]))->toThrow(ValidationException::class);
});

it('renders cadence settings and actual window indicators', function () {
    Livewire::test(\Modules\FollowUp\Livewire\Settings::class)->assertSee('Cadencia dentro de 24 horas')->assertSet('cadence.first', 5);
    Livewire::withQueryParams(['asesor' => (string) $this->bot->id])->test(\Modules\FollowUp\Livewire\Settings::class)
        ->assertSee('WhatsApp con texto libre: primer momento a las 5 h desde el prospecto')
        ->assertSee('Intervalo general inicial:');
    Livewire::withQueryParams(['oportunidad' => (string) $this->op->id])->test(\Modules\FollowUp\Livewire\Opportunities::class)->assertStatus(200);
});

it('keeps a 24 hour UTC duration across daylight saving transitions', function (string $date) {
    $this->travelTo(CarbonImmutable::parse($date, 'America/New_York'));
    $this->inbound->update(['provider_timestamp' => now()->subHours(4)]);
    $w = app(WhatsAppWindow::class)->inspect($this->conversation->id);
    expect($w['last_customer_message_at']->diffInSeconds($w['expires_at']))->toBe(86400.0);
})->with(['2026-03-08 05:00:00', '2026-11-01 05:00:00']);

it('serializes two real workers reserving the same cadence slot', function () {
    $this->travel(1)->hours();
    $db = config('database.connections.mysql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'crm_mca_test', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'APP_KEY' => config('app.key')];
    \Illuminate\Support\Facades\DB::commit();
    $workers = [];
    try {
        $start = (string) (microtime(true) + 2);
        for ($i = 0; $i < 2; $i++) {
            $worker = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', base_path('Modules/FollowUp/tests/Support/stage8-worker.php'), (string) $this->admin->institution_id, (string) $this->admin->id, (string) $this->op->id, 'plan', $start, now()->toDateTimeString()], base_path(), $env);
            $worker->start();
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        expect(FollowUpAction::query()->whereNotNull('cadence_key')->count())->toBe(1)
            ->and(FollowUpAction::query()->sole()->cadence_slot)->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        \Illuminate\Support\Facades\DB::beginTransaction();
    }
});

it('runs contextual drafting approval a simulated send and cancels the second contact on reply', function () {
    $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    \Modules\Integrations\Models\AiProcessConfig::factory()->create(['bot_id' => $this->bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $client = Mockery::mock(\Modules\Ai\Services\AiChatClient::class);
    $client->shouldReceive('chat')->once()->andReturn(new \Modules\Ai\Services\AiChatResponse(json_encode([
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar dudas', 'draft_text' => '¿Qué dudas te gustaría resolver?',
        'language' => 'es', 'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta pendiente', 'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ], JSON_THROW_ON_ERROR), 'qwen', 'qwen-plus', 40, 20, 10));
    app()->instance(\Modules\Ai\Services\AiChatClient::class, $client);
    $action = cadencePropose($this);
    $drafts = app(\Modules\FollowUp\Services\FollowUpDraftService::class);
    $action = $drafts->request($this->admin, $action->id, 0);
    $drafts->generate($action->id, $action->draft_reservation);
    expect($action->fresh()->draft_state)->toBe('review');
    $executor = app(\Modules\FollowUp\Services\FollowUpExecutor::class);
    $executor->approve($this->admin, $action->id, 1);
    config(['followup.execution_enabled' => true]);
    foreach (['global' => 0, 'institution' => $this->admin->institution_id, 'advisor' => $this->bot->id, 'channel' => $this->channel->id] as $scope => $id) {
        app(\Modules\FollowUp\Services\ExecutionSwitches::class)->set($this->admin, $scope, $id, true, 10, 'synthetic test only');
    }
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.cadence.fake']]], 200)]);
    $level = \Illuminate\Support\Facades\DB::transactionLevel();
    $executor->execute($action->id);
    $executor->execute($action->id);
    expect($action->fresh()->status)->toBe('sent')->and(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($level);
    Http::assertSentCount(1);
    $this->travel(9)->hours();
    app(OpportunityDetector::class)->detectConversation($this->conversation->id, now());
    $this->op->refresh();
    $second = cadencePropose($this);
    expect($second->cadence_slot)->toBe(2);
    $reply = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()]);
    event(new \Modules\Social\Events\SocialMessageStored($this->admin->institution_id, $this->conversation->id, $reply->id, 'inbound'));
    expect($second->fresh()->status)->toBe('cancelled')->and(app(WhatsAppCadence::class)->summary($this->op->fresh())['sent'])->toBe(1);
    Http::assertSentCount(1);
});

it('detects early silence only when WhatsApp cadence is enabled', function () {
    $this->op->delete();
    expect(app(OpportunityDetector::class)->detectDue()['detected'])->toBe(1);
    expect(FollowUpOpportunity::query()->count())->toBe(1);
});

it('validates templates even within an open window', function () {
    $template = \Modules\Social\Models\SocialWhatsAppTemplate::factory()->create(['social_channel_id' => $this->channel->id, 'status' => 'REJECTED', 'category' => 'MARKETING']);
    $this->setting->update(['first_follow_up_hours' => 1, 'minimum_interval_hours' => 1]);
    $decision = app(FollowUpPolicyEngine::class)->evaluate(new PolicyRequest($this->op, 'schedule', 'whatsapp', 'requested_follow_up', now(), now()->addDay(), $template->id));
    expect($decision->reasonCode)->toBe('whatsapp_template_not_approved');
});

it('preserves provider time provenance and duplicate identity through real ingestion', function (?int $age, string $expected) {
    $message = new \Modules\Social\Services\NormalizedMessage(
        provider: 'whatsapp', channelExternalId: $this->channel->external_id,
        conversationExternalId: 'cadence.synthetic.ingest', contactName: 'Prospecto ficticio',
        contactExternalId: '15550000001', contactAvatarUrl: null, messageExternalId: 'wamid.cadence.ingest',
        type: 'text', body: 'Consulta ficticia', attachments: null,
        providerTimestamp: $age === null ? null : CarbonImmutable::now()->subHours($age),
    );
    $ingest = app(\Modules\Social\Services\SocialIngestService::class);
    $first = $ingest->ingest($message);
    $duplicate = $ingest->ingest($message);
    expect($first->status)->toBe('created')->and($duplicate->status)->toBe('duplicate')
        ->and($duplicate->messageId)->toBe($first->messageId)
        ->and(app(WhatsAppWindow::class)->inspect($first->conversationId)['state'])->toBe($expected);
    Http::assertNothingSent();
})->with([[null, 'unknown'], [-1, 'unknown'], [4, 'open'], [26, 'expired']]);

it('enforces the same cadence on Cloud API and Coexistence', function (string $connection) {
    $this->channel->update(['connection_status' => $connection]);
    expect(cadencePropose($this)->cadence_slot)->toBe(1);
    Http::assertNothingSent();
})->with(['connected_cloud_api', 'connected_coexistence']);

it('keeps recipient limits across multiple linked opportunities', function () {
    $contact = \Modules\Crm\Models\Contact::factory()->create();
    $this->op->update(['contact_id' => $contact->id]);
    $this->setting->update(['maximum_attempts' => 1]);
    $other = FollowUpOpportunity::factory()->create(['contact_id' => $contact->id]);
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $other->id, 'sequence' => 1, 'classification' => 'general', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $other->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'sent', 'policy_status' => 'allowed', 'policy_reason_code' => 'schedule_allowed', 'policy_version' => '2.0', 'eligibility_version' => 2, 'idempotency_key' => hash('sha256', 'other recipient action'), 'attempt_number' => 1, 'cycle_attempt_number' => 1]);
    $action->forceFill(['dispatch_at' => now()->subDay()])->save();
    expect(cadencePropose($this)->block_reason)->toBe('recipient_attempt_limit');
});

it('normalizes malformed WhatsApp provider timestamps as unverified evidence', function (mixed $timestamp) {
    $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => $this->channel->external_id],
        'messages' => [['from' => '15550000002', 'id' => 'wamid.invalid.date', 'timestamp' => $timestamp, 'type' => 'text', 'text' => ['body' => 'Consulta ficticia']]],
    ]]]]]];
    $messages = app(\Modules\Social\Services\MetaWebhookNormalizer::class)->normalize('whatsapp', $payload);
    expect($messages)->toHaveCount(1)->and($messages[0]->providerTimestamp)->toBeNull();
    $result = app(\Modules\Social\Services\SocialIngestService::class)->ingest($messages[0]);
    expect(app(WhatsAppWindow::class)->inspect($result->conversationId)['state'])->toBe('unknown');
    Http::assertNothingSent();
})->with([null, 'not-a-date', '1e99', '-1', '999999999999999999999999', '4102444800']);

it('does not allow an approved template to bypass the window contact limit', function () {
    $this->setting->update(['maximum_attempts' => 10, 'first_follow_up_hours' => 1, 'minimum_interval_hours' => 1, 'whatsapp_cadence' => array_replace(WhatsAppCadence::DEFAULTS, ['enabled' => true, 'maximum' => 1])]);
    $action = cadencePropose($this);
    $action->forceFill(['status' => 'sent', 'dispatch_at' => now()])->save();
    $template = \Modules\Social\Models\SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $this->channel->id, 'category' => 'MARKETING']);
    $this->travel(9)->hours();
    $decision = app(FollowUpPolicyEngine::class)->evaluate(new PolicyRequest($this->op, 'schedule', 'whatsapp', 'requested_follow_up', now(), now()->addDay(), $template->id));
    expect($decision->reasonCode)->toBe('cadence_maximum_reached');
    Http::assertNothingSent();
});
