<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\ExecutionSwitches;
use Modules\FollowUp\Services\FollowUpDraftContext;
use Modules\FollowUp\Services\FollowUpExecutor;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;

function stage8Http(callable|array $callback): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

beforeEach(function () {
    Http::preventStrayRequests();
    stage8Http(['*' => Http::response(['messages' => [['id' => 'wamid.followup.test']]], 200)]);
    Queue::fake();
    config(['followup.policy_enabled' => true, 'followup.execution_enabled' => true, 'social.advisor.autoreply_enabled' => false]);
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $this->admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->create();
    $this->channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id, 'advisor_enabled' => true, 'is_active' => true, 'connection_status' => 'connected_cloud_api']);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $this->channel->id, 'provider' => 'whatsapp', 'contact_name' => 'Ana', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'body' => '¿Qué información tiene el programa?', 'provider_timestamp' => now()->subHours(3)]);
    $last = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'body' => '¿Qué aspecto te interesa conocer?', 'provider_timestamp' => now()->subHours(2)]);
    $this->opportunity = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $this->channel->id, 'social_conversation_id' => $this->conversation->id, 'provider' => 'whatsapp', 'source_last_message_id' => $last->id, 'source_last_message_at' => now()->subHours(2), 'last_inbound_at' => now()->subHours(3), 'last_outbound_at' => now()->subHours(2), 'status' => 'open', 'contact_id' => null, 'lead_id' => null]);
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'sequence' => 1, 'classification' => 'general', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $this->setting = FollowUpSetting::query()->create(['enabled' => true, 'mode' => 'supervised', 'first_follow_up_hours' => 1, 'minimum_interval_hours' => 1, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'business_hours_start' => '00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC', 'allowed_channels' => ['whatsapp']]);
    FollowUpAdvisorSetting::query()->create(['bot_id' => $this->bot->id, 'enabled' => true]);
    app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'test_opt_in', 'test_evidence');
    $this->action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'draft', 'policy_status' => 'allowed', 'policy_reason_code' => 'schedule_allowed', 'policy_version' => '2.0', 'eligibility_version' => 2, 'idempotency_key' => hash('sha256', 'execution'), 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'scheduled_at' => now()->subMinute(), 'expires_at' => now()->addDay()]);
    stage8Draft($this->action);
    $switches = app(ExecutionSwitches::class);
    foreach (['global' => 0, 'institution' => $institution->id, 'advisor' => $this->bot->id, 'channel' => $this->channel->id] as $scope => $id) {
        $switches->set($this->admin, $scope, $id, true, 10, 'test_only_capacity');
    }
    $this->executor = app(FollowUpExecutor::class);
    $this->executor->approve($this->admin, $this->action->id, 1);
});

function stage8Draft(FollowUpAction $action): void
{
    $action->forceFill(['draft_state' => 'review', 'draft_version' => 1, 'draft_expires_at' => now()->addHours(12), 'draft_generated_at' => now(), 'draft_payload' => [
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar dudas', 'draft_text' => '¿Qué dudas te gustaría resolver?', 'language' => 'es', 'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta pendiente', 'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ]])->save();
    $action->forceFill(['context_fingerprint' => app(FollowUpDraftContext::class)->build($action)['fingerprint']])->save();
}

it('stage8 rechaza actores inactivos o ajenos al contexto en servicios internos', function (string $operation, string $condition) {
    if ($condition === 'inactive') {
        $this->admin->update(['status' => 'inactive']);
    } else {
        app(CurrentInstitution::class)->set(Institution::factory()->create()->id);
    }
    expect(fn () => match ($operation) {
        'consent' => app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'fixture', 'fixture'),
        'settings' => app(\Modules\FollowUp\Services\FollowUpSettingsService::class)->saveInstitution($this->admin, []),
        'draft' => app(\Modules\FollowUp\Services\FollowUpDraftService::class)->request($this->admin, $this->action->id, 1),
        'plan' => app(\Modules\FollowUp\Services\FollowUpActionPlanner::class)->propose($this->admin, $this->opportunity->id, 'whatsapp', 'requested_follow_up', now(), now()->addDay()),
    })->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with(['consent', 'settings', 'draft', 'plan'])->with(['inactive', 'tenant']);

it('stage8 conserva una aprobación vigente ante una petición duplicada', function () {
    $approvedAt = $this->action->fresh()->approved_at->toDateTimeString();
    $this->travel(1)->minutes();
    $this->executor->approve($this->admin, $this->action->id, 1);
    expect($this->action->fresh()->approved_at->toDateTimeString())->toBe($approvedAt);
    expect(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'execution.approved')->count())->toBe(1);
});

it('stage8 el motor rechaza una solicitud atribuida a un administrador inactivo', function () {
    $this->admin->update(['status' => 'inactive']);
    $action = $this->action->fresh();
    $decision = app(\Modules\FollowUp\Services\FollowUpPolicyEngine::class)->evaluate(new \Modules\FollowUp\Data\PolicyRequest($action->opportunity, 'schedule', $action->channel, $action->purpose, $action->scheduled_at, $action->expires_at, actor: $this->admin, action: $action));
    expect($decision->allows())->toBeFalse()->and($decision->reasonCode)->toBe('actor_not_authorized');
});

it('stage8 recorre detección políticas borrador aprobación ejecución respuesta y conversión', function () {
    $this->action->delete();
    $this->opportunity->delete();
    $detector = app(\Modules\FollowUp\Services\OpportunityDetector::class);
    expect($detector->detectConversation($this->conversation->id, now()))->toBe('detected');
    $op = FollowUpOpportunity::query()->firstOrFail();
    $contact = \Modules\Crm\Models\Contact::factory()->create();
    $lead = \Modules\Crm\Models\Lead::factory()->create(['contact_id' => $contact->id, 'status' => 'qualified']);
    $commercial = app(\Modules\FollowUp\Services\FollowUpCommercialCoordinator::class);
    $commercial->link($this->admin, $op->id, $op->commercial_version, $contact->id, $lead->id, null, 'Evidencia ficticia de integración');
    app(ConsentService::class)->record($this->admin, $contact->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'test_form', 'fixture_evidence');
    $action = app(\Modules\FollowUp\Services\FollowUpActionPlanner::class)->propose($this->admin, $op->id, 'whatsapp', 'requested_follow_up', now()->subMinute(), now()->addDay());
    expect($action->status)->toBe('pending_approval');
    $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    \Modules\Integrations\Models\AiProcessConfig::factory()->create(['bot_id' => $this->bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $client = Mockery::mock(\Modules\Ai\Services\AiChatClient::class);
    $client->shouldReceive('chat')->once()->andReturn(new \Modules\Ai\Services\AiChatResponse(json_encode([
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar dudas', 'draft_text' => '¿Qué dudas te gustaría resolver?',
        'language' => 'es', 'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta pendiente', 'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ], JSON_THROW_ON_ERROR), 'qwen', 'qwen-plus', 40, 20, 10));
    app()->instance(\Modules\Ai\Services\AiChatClient::class, $client);
    $drafts = app(\Modules\FollowUp\Services\FollowUpDraftService::class);
    $action = $drafts->request($this->admin, $action->id, 0);
    $drafts->generate($action->id, $action->draft_reservation);
    $action->refresh();
    expect($action->draft_state)->toBe('review');
    expect(DB::table('follow_up_actions')->where('id', $action->id)->value('draft_payload'))->not->toContain('resolver');
    $this->executor->approve($this->admin, $action->id, $action->draft_version);
    $this->executor->execute($action->id);
    expect($action->fresh()->status)->toBe('sent');
    Http::assertSentCount(1);
    $this->travel(1)->minutes();
    $reply = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'body' => '¿Qué información tiene el programa?', 'provider_timestamp' => now()]);
    event(new \Modules\Social\Events\SocialMessageStored($this->admin->institution_id, $this->conversation->id, $reply->id, 'inbound'));
    expect($op->fresh()->status)->not->toBe('open');
    app(\Modules\Crm\Services\LeadService::class)->changeStatus($lead, \Modules\Crm\Enums\LeadStatus::Enrolled);
    $commercial->sync($op->id);
    expect($op->fresh()->commercial_close_reason)->toBe('converted');
    $aggregate = app(\Modules\FollowUp\Services\FollowUpAnalyticsAggregator::class);
    $first = $aggregate->reconcile();
    expect($aggregate->reconcile())->toBe($first);
    $report = app(\Modules\FollowUp\Services\FollowUpMetricsService::class)->report(now()->subDay()->toDateString(), now()->toDateString());
    expect($report['counts']['executed'])->toBe(1)->and($report['counts']['responders'])->toBe(1)
        ->and($report['counts']['conversions_associated'])->toBe(1);
    $this->executor->execute($action->id);
    Http::assertSentCount(1);
});

it('stage8 rechaza una plantilla alterada en memoria aunque conserve su identificador', function (string $stage) {
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $this->channel->id, 'category' => 'MARKETING', 'language' => 'es', 'last_synced_at' => now(), 'components' => [['type' => 'BODY', 'text' => '¿Te gustaría retomar tu consulta?']]]);
    $this->action->update(['social_whatsapp_template_id' => $template->id]);
    stage8Draft($this->action->refresh());
    $this->executor->approve($this->admin, $this->action->id, 1);
    $this->action->refresh()->forceFill(['status' => 'claimed', 'execution_token' => (string) \Illuminate\Support\Str::uuid()])->save();
    $outbound = app(\Modules\Social\Services\SocialOutboundService::class);
    $content = app(\Modules\FollowUp\Services\ExecutionContent::class)->build($this->action->fresh());
    $text = $content['text'];
    $template = $content['template'];
    if ($stage === 'prepare') {
        $template->name = 'unapproved_substitution';
        expect(fn () => $outbound->prepareFollowUp($this->conversation, $text, $this->bot->id, $this->action->id, $template))->toThrow(\Illuminate\Validation\ValidationException::class);
    } else {
        $message = $outbound->prepareFollowUp($this->conversation, $text, $this->bot->id, $this->action->id, $template);
        $this->action->forceFill(['status' => 'sending', 'dispatch_at' => now(), 'social_message_id' => $message->id])->save();
        $template->name = 'unapproved_substitution';
        expect(fn () => $outbound->dispatchFollowUp($message, $template))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    Http::assertNothingSent();
})->with(['prepare', 'dispatch']);

it('stage8 consume jobs MySQL duplicados y respeta apagado al reanudar', function (bool $enabled) {
    config(['followup.execution_enabled' => $enabled]);
    $queue = new \Illuminate\Queue\DatabaseQueue(DB::connection(), 'jobs', 'followup-execution', 90, false);
    $queue->setContainer(app());
    $queue->setConnectionName('database');
    for ($i = 0; $i < 2; $i++) {
        $queue->push(new \Modules\FollowUp\Jobs\ExecuteFollowUp($this->admin->institution_id, $this->action->id));
    }
    expect($queue->size())->toBe(2);
    $context = app(CurrentInstitution::class);
    $other = Institution::factory()->create();
    $context->set($other->id);
    for ($i = 0; $i < 2; $i++) {
        $job = $queue->pop();
        expect($job)->not->toBeNull();
        app('queue.worker')->process('database', $job, new \Illuminate\Queue\WorkerOptions(maxTries: 1));
        expect($context->id())->toBe($other->id);
    }
    expect($queue->size())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
    $context->set($this->admin->institution_id);
    expect($this->action->fresh()->status)->toBe($enabled ? 'sent' : 'suppressed');
    Http::assertSentCount($enabled ? 1 : 0);
})->with([true, false]);

it('stage8 serializa detección planificación ejecución y reconciliación en conexiones independientes', function () {
    expect(app()->environment())->toBe('testing')->and(DB::connection()->getDatabaseName())->toBe('crm_mca_test');
    $this->action->delete();
    $this->opportunity->delete();
    DB::commit();
    $workers = [];
    try {
        $db = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'crm_mca_test', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'APP_KEY' => config('app.key')];
        $race = function (string $mode, int $id) use (&$workers, $env): int {
            $workers = [];
            $at = (string) (microtime(true) + 2);
            $now = now()->toDateTimeString();
            for ($i = 0; $i < 2; $i++) {
                $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('Modules/FollowUp/tests/Support/stage8-worker.php'), (string) $this->admin->institution_id, (string) $this->admin->id, (string) $id, $mode, $at, $now], base_path(), $env);
                $worker->start();
                $workers[] = $worker;
            }
            $calls = 0;
            foreach ($workers as $worker) {
                $worker->wait();
                expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $calls += $result['http_calls'] + $result['ai_calls'];
            }

            return $calls;
        };
        expect($race('detect', $this->conversation->id))->toBe(0);
        expect(FollowUpOpportunity::query()->count())->toBe(1)->and(FollowUpCycle::query()->count())->toBe(1);
        $op = FollowUpOpportunity::query()->firstOrFail();
        expect($race('plan', $op->id))->toBe(0);
        expect(FollowUpAction::query()->count())->toBe(1);
        $action = FollowUpAction::query()->firstOrFail();
        $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        \Modules\Integrations\Models\AiProcessConfig::factory()->create(['bot_id' => $this->bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
        expect($race('request', $action->id))->toBe(0);
        expect($action->fresh()->draft_version)->toBe(1);
        expect($race('generate', $action->id))->toBe(1);
        expect($action->fresh()->draft_state)->toBe('review');
        expect($race('approve', $action->id))->toBe(0);
        expect(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'execution.approved')->count())->toBe(1);
        expect($race('execute', $action->id))->toBe(1);
        expect($action->fresh()->status)->toBe('sent')->and(SocialMessage::query()->count())->toBe(3);
        SocialMessage::query()->findOrFail($action->fresh()->social_message_id)->update(['status' => 'read']);
        expect($race('reconcile', $action->id))->toBe(0);
        expect($action->fresh()->status)->toBe('read');
        expect(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'execution.dispatch_committed')->count())->toBe(1);
        expect(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'execution.read')->count())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::beginTransaction();
    }
});
