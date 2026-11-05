<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\FollowUp\Livewire\Analytics;
use Modules\FollowUp\Models\AnalyticsFact;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\FollowUpAnalyticsAggregator;
use Modules\FollowUp\Services\FollowUpInsightsService;
use Modules\FollowUp\Services\FollowUpMetricsService;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialMessage;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 4)->startOfDay());
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    $this->institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($this->institution->id);
    $this->admin = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $contact = Contact::factory()->create();
    $this->lead = Lead::factory()->create(['contact_id' => $contact->id, 'status' => 'enrolled']);
    $this->op = FollowUpOpportunity::factory()->create(['contact_id' => $contact->id, 'lead_id' => $this->lead->id, 'detected_at' => '2026-10-01 12:00:00']);
    $this->op->forceFill(['commercial_link_source' => 'manual', 'commercial_link_evidence' => 'Evidencia de prueba', 'commercial_close_reason' => 'converted'])->save();
    $this->cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->op->id, 'sequence' => 1, 'reason_codes' => [], 'classification' => 'general_interest', 'started_at' => '2026-10-01 12:00:00', 'detected_at' => '2026-10-01 12:00:00']);
    $this->sent = SocialMessage::factory()->create(['social_conversation_id' => $this->op->social_conversation_id, 'direction' => 'outbound', 'status' => 'read', 'external_message_id' => 'fixture-confirmed', 'provider_timestamp' => '2026-10-10 12:00:00']);
    $this->action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $this->op->id, 'follow_up_cycle_id' => $this->cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'status' => 'read', 'policy_status' => 'allowed', 'policy_version' => '2.0', 'policy_reason_code' => 'send_allowed', 'idempotency_key' => hash('sha256', 'analytics-fixture'), 'scheduled_at' => '2026-10-10 12:00:00', 'expires_at' => '2026-10-12 12:00:00']);
    $this->action->forceFill(['social_message_id' => $this->sent->id, 'dispatch_at' => '2026-10-10 12:00:00', 'created_at' => '2026-10-09 12:00:00'])->save();
    $this->reply = SocialMessage::factory()->create(['social_conversation_id' => $this->op->social_conversation_id, 'direction' => 'inbound', 'provider_timestamp' => '2026-10-11 12:00:00']);
    $this->conversion = FollowUpEvent::query()->create(['follow_up_opportunity_id' => $this->op->id, 'event_type' => 'commercial.synchronized', 'safe_data' => ['basis' => 'confirmed_crm', 'result' => 'converted', 'lead_id' => $this->lead->id], 'occurred_at' => '2026-10-20 12:00:00']);
    $this->aggregate = app(FollowUpAnalyticsAggregator::class);
    $this->metrics = app(FollowUpMetricsService::class);
});

afterEach(function () {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('mide evidencia confirmada denominadores respuesta y conversión sin mutar fuentes', function () {
    $before = $this->action->fresh()->getAttributes();
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    expect($report['counts']['detected'])->toBe(1)->and($report['counts']['executed'])->toBe(1)
        ->and($report['counts']['responders'])->toBe(1)->and($report['rates']['response'])->toBe(100.0)
        ->and($report['counts']['conversions_observed'])->toBe(1)->and($report['counts']['conversions_associated'])->toBe(1)
        ->and($report['counts']['delivered'])->toBe(1)->and($report['counts']['read'])->toBe(1)
        ->and($report['usage']['cost'])->toBeNull()->and($this->action->fresh()->getAttributes())->toBe($before);
});

it('no confunde ambigüedad fallo ni aceptación con entrega', function (string $state) {
    $this->action->update(['status' => $state]);
    $this->sent->update(['status' => $state]);
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    expect($report['counts']['executed'])->toBe($state === 'sent' ? 1 : 0)
        ->and($report['counts']['delivered'])->toBe(0)
        ->and($report['counts']['unknown'])->toBe($state === 'delivery_unknown' ? 1 : 0)
        ->and($report['counts']['failed'])->toBe($state === 'failed' ? 1 : 0);
})->with(['delivery_unknown', 'failed', 'sent']);

it('exige identificador del proveedor y dirección y conversación correctas', function (string $change) {
    if ($change === 'id') {
        $this->sent->update(['external_message_id' => null]);
    }
    if ($change === 'direction') {
        $this->sent->update(['direction' => 'inbound']);
    }
    if ($change === 'conversation') {
        $this->sent->update(['social_conversation_id' => FollowUpOpportunity::factory()->create()->social_conversation_id]);
    }
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['executed'])->toBe(0);
})->with(['id', 'direction', 'conversation']);

it('respeta los límites de la ventana de respuesta', function (string $date, int $expected) {
    $this->reply->update(['provider_timestamp' => $date]);
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['responders'])->toBe($expected);
})->with([['2026-10-10 11:59:59', 0], ['2026-10-10 12:00:00', 0], ['2026-10-17 12:00:00', 1], ['2026-10-17 12:00:01', 0]]);

it('reconcilia eventos tardíos correcciones y eliminaciones sin acumulación', function () {
    $this->reply->delete();
    $this->aggregate->reconcile();
    $count = AnalyticsFact::query()->count();
    $this->aggregate->reconcile();
    expect(AnalyticsFact::query()->count())->toBe($count);
    SocialMessage::factory()->create(['social_conversation_id' => $this->op->social_conversation_id, 'direction' => 'inbound', 'provider_timestamp' => '2026-10-12 12:00:00']);
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['responders'])->toBe(1);
    $this->lead->update(['status' => 'qualified']);
    $this->aggregate->reconcile();
    expect(AnalyticsFact::query()->where('kind', 'conversion')->count())->toBe(0);
});

it('una matrícula no se duplica por eventos ciclos o seguimientos y usa el último elegible', function () {
    $this->conversion->replicate()->save();
    $this->op->update(['cycle' => 2]);
    $second = $this->action->replicate();
    $message = $this->sent->replicate();
    $message->forceFill(['external_message_id' => 'fixture-second', 'provider_timestamp' => '2026-10-15 12:00:00'])->save();
    $second->forceFill(['social_message_id' => $message->id, 'idempotency_key' => hash('sha256', 'second'), 'dispatch_at' => '2026-10-15 12:00:00'])->save();
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    expect($report['counts']['detected'])->toBe(1)->and($report['counts']['subjects'])->toBe(1)
        ->and($report['counts']['conversions_associated'])->toBe(1)
        ->and(AnalyticsFact::query()->where('kind', 'conversion')->first()->action_id)->toBe($second->id);
});

it('no atribuye sin identidad o fecha verificable ni fuera de ventana', function (string $reason) {
    if ($reason === 'identity') {
        $this->op->forceFill(['commercial_link_source' => 'unresolved'])->save();
    }
    if ($reason === 'date') {
        $this->conversion->delete();
    }
    if ($reason === 'window') {
        $this->conversion->update(['occurred_at' => '2026-09-01 12:00:00']);
    }
    if ($reason === 'no_send') {
        $this->action->update(['status' => 'pending_approval']);
    }
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['conversions_associated'])->toBe(0);
})->with(['identity', 'date', 'window', 'no_send']);

it('no crea una conversión por una inferencia del asesor', function () {
    $this->lead->update(['status' => 'new']);
    $this->op->forceFill(['commercial_result' => ['kind' => 'inferred', 'code' => 'converted']])->save();
    $this->aggregate->reconcile();
    expect(AnalyticsFact::query()->where('kind', 'conversion')->exists())->toBeFalse();
});

it('aísla institución roles y manipulación de filtros', function () {
    $this->aggregate->reconcile();
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, function () {
        FollowUpOpportunity::factory()->count(3)->create();
        $this->aggregate->reconcile();
    });
    expect(AnalyticsFact::query()->where('kind', 'opportunity')->count())->toBe(1);
    Livewire::test(Analytics::class)->set('from', '2026-10-01')->set('to', '2026-10-31')->set('filters', ['institution_id' => (string) $other->id])->assertSee('Analítica comercial')->assertViewHas('report', fn ($r) => $r['counts']['detected'] === 1);
    $this->admin->update(['role' => 'marketing']);
    $this->get(route('followup.analytics'))->assertForbidden();
});

it('muestra sin datos suficientes cuando no hay denominador y no llama IA al renderizar', function () {
    $this->aggregate->reconcile();
    Livewire::test(Analytics::class)->set('from', '2025-01-01')->set('to', '2025-01-31')->assertSee('Sin datos suficientes')->assertSee('No disponibles');
    expect(FollowUpMetricsService::rate(0, 0))->toBeNull();
});

it('abstiene recomendaciones sin volumen y mantiene catálogos seguros ante fallo IA', function () {
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    $service = app(FollowUpInsightsService::class);
    expect($service->recommend($this->admin, $report, $report)['status'])->toBe('abstained');
    $report['counts']['subjects'] = 30;
    $report['counts']['failed'] = 3;
    $report['open_windows'] = 0;
    $report['quality'] = [];
    $result = $service->recommend($this->admin, $report, $report, $this->op->bot_id);
    expect($result['status'])->toBe('provider_unavailable')->and($result['items'][0]['metric'])->toBe('failed');
});

it('mantiene la consulta del dashboard independiente del número de hechos', function () {
    $this->aggregate->reconcile();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->metrics->report('2026-10-01', '2026-10-31');
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 200; $i++) {
        AnalyticsFact::query()->create(['kind' => 'opportunity', 'source_key' => 'volume-'.$i, 'occurred_at' => '2026-10-02', 'data' => [], 'generation' => '00000000-0000-0000-0000-000000000000']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->metrics->report('2026-10-01', '2026-10-31');
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($large)->toBe($small);
});

it('deduplica generaciones y aprobaciones conservando su primera fecha', function () {
    foreach (['draft.generated', 'execution.approved'] as $type) {
        foreach (['2026-10-02', '2026-10-03'] as $date) {
            FollowUpEvent::query()->create(['follow_up_opportunity_id' => $this->op->id, 'follow_up_action_id' => $this->action->id, 'event_type' => $type, 'safe_data' => ['version' => 1], 'occurred_at' => $date]);
        }
    }
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    expect($report['counts']['generated'])->toBe(1)->and($report['counts']['approved'])->toBe(1)
        ->and($report['rates']['approval'])->toBe(100.0)
        ->and(AnalyticsFact::query()->where('kind', 'generation')->first()->occurred_at->toDateString())->toBe('2026-10-02');
});

it('rechaza fechas inválidas y recupera la interfaz al corregirlas', function () {
    Livewire::test(Analytics::class)->set('from', '2027-01-01')->assertSee('No se muestran indicadores')
        ->set('to', '2027-01-31')->assertSee('Tasas y denominadores');
    expect(fn () => $this->metrics->period('2026-02-30', '2026-03-01'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('filtra dimensiones sin mezclar periodos y conserva denominadores vacíos', function () {
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31', ['bot_id' => (string) $this->op->bot_id])['counts']['executed'])->toBe(1)
        ->and($this->metrics->report('2026-10-01', '2026-10-31', ['channel' => 'messenger'])['rates']['response'])->toBeNull()
        ->and($this->metrics->report('2026-09-01', '2026-09-30')['counts']['detected'])->toBe(0);
});

it('reutiliza IA solo bajo demanda y rechaza recomendaciones fuera del catálogo', function (string $output, string $status) {
    $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    \Modules\Integrations\Models\AiProcessConfig::factory()->create(['bot_id' => $this->op->bot_id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $client = Mockery::mock(\Modules\Ai\Services\AiChatClient::class);
    app()->instance(\Modules\Ai\Services\AiChatClient::class, $client);
    $client->shouldReceive('chat')->once()->withArgs(function ($integration, $model, $messages, $params, $context) {
        expect($context->process)->toBe('followup_insights')->and($messages[1]['content'])->not->toContain('contact_id', 'lead_id', 'Evidencia de prueba');

        return true;
    })->andReturn(new \Modules\Ai\Services\AiChatResponse($output, 'qwen', 'qwen-plus'));
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    $report['counts']['subjects'] = 30;
    $report['counts']['failed'] = 3;
    $report['open_windows'] = 0;
    $report['quality'] = [];
    $result = app(FollowUpInsightsService::class)->recommend($this->admin, $report, $report, $this->op->bot_id);
    expect($result['status'])->toBe($status)->and($result['items'][0]['id'])->toBe('review_errors');
})->with([['{"ids":["review_errors"]}', 'ai_selected'], ['{"ids":["disable_consent"]}', 'provider_unavailable'], ['invalid JSON', 'provider_unavailable']]);

it('niega llamadas Livewire de usuarios sin permiso y actores de otra institución', function () {
    $other = Institution::factory()->create();
    $actor = User::factory()->create(['institution_id' => $other->id, 'role' => 'admin', 'status' => 'active']);
    expect(fn () => app(FollowUpInsightsService::class)->recommend($actor, [], []))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->admin->update(['role' => 'admissions']);
    Livewire::test(Analytics::class)->assertForbidden();
});

it('serializa dos reconstrucciones MySQL concurrentes sin duplicar hechos', function () {
    expect(app()->environment())->toBe('testing')->and(DB::connection()->getDatabaseName())->toBe('crm_mca_test');
    DB::commit();
    $workers = [];
    try {
        $db = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'crm_mca_test', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'APP_KEY' => config('app.key')];
        for ($i = 0; $i < 2; $i++) {
            $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('Modules/FollowUp/tests/Support/analytics-worker.php'), (string) $this->institution->id], base_path(), $env);
            $worker->start();
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
        }
        expect(AnalyticsFact::query()->where('kind', 'action')->count())->toBe(1)
            ->and(AnalyticsFact::query()->where('kind', 'conversion')->count())->toBe(1)
            ->and(AnalyticsFact::query()->distinct()->count('generation'))->toBe(1);
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

it('distingue una respuesta sin seguimiento confirmado de una respuesta asociada', function () {
    $this->action->update(['status' => 'delivery_unknown']);
    $this->aggregate->reconcile();
    $report = $this->metrics->report('2026-10-01', '2026-10-31');
    expect($report['counts']['unassociated_responses'])->toBe(1)->and($report['counts']['responders'])->toBe(0);
});

it('cuenta retiros verificables una vez por identidad y respeta canal y finalidad', function () {
    $consent = \Modules\FollowUp\Models\CommunicationConsent::query()->create(['contact_id' => $this->op->contact_id, 'subject_key' => 'contact:'.$this->op->contact_id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'state' => 'withdrawn', 'policy_version' => '2.0']);
    foreach ([1, 2] as $unused) {
        \Modules\FollowUp\Models\CommunicationConsentEvent::query()->create(['communication_consent_id' => $consent->id, 'from_state' => 'granted', 'to_state' => 'withdrawn', 'source' => 'test', 'evidence' => 'Test only', 'policy_version' => '2.0', 'occurred_at' => '2026-10-12']);
    }
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['excluded'])->toBe(1);
    $consent->update(['channel' => 'instagram']);
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['excluded'])->toBe(0);
});

it('mide telemetría registrada sin inventar tarifas ni atribución por canal', function () {
    $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen']);
    \Modules\Ai\Models\AiUsageEvent::query()->create(['process' => 'followup_draft', 'bot_id' => $this->op->bot_id, 'integration_id' => $integration->id, 'provider' => 'qwen', 'model' => 'fixture', 'input_tokens' => 100, 'output_tokens' => 40, 'latency_ms' => 250, 'status' => 'success', 'created_at' => '2026-10-10']);
    $this->aggregate->reconcile();
    $usage = $this->metrics->report('2026-10-01', '2026-10-31')['usage'];
    expect($usage['calls'])->toBe(1)->and($usage['input_tokens'])->toBe(100)->and($usage['output_tokens'])->toBe(40)->and($usage['cost'])->toBeNull()
        ->and($this->metrics->report('2026-10-01', '2026-10-31', ['channel' => 'whatsapp'])['usage']['calls'])->toBe(0);
});

it('cuenta reuniones reales vinculadas y excluye tareas y cancelaciones', function () {
    $activity = \Modules\Crm\Models\Activity::query()->create(['contact_id' => $this->op->contact_id, 'lead_id' => $this->lead->id, 'assigned_to_user_id' => $this->admin->id, 'title' => 'Fixture', 'type' => 'video_call', 'starts_at' => '2026-10-15 12:00:00', 'ends_at' => '2026-10-15 13:00:00', 'timezone' => 'UTC', 'status' => 'completed', 'completed_at' => '2026-10-15 13:00:00', 'uid' => (string) \Illuminate\Support\Str::uuid()]);
    $activity->forceFill(['created_at' => '2026-10-12'])->save();
    $this->op->forceFill(['commercial_activity_id' => $activity->id])->save();
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['meetings_completed'])->toBe(1);
    $activity->update(['type' => 'task']);
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['meetings'])->toBe(0);
    $activity->update(['type' => 'video_call', 'status' => 'cancelled']);
    $this->aggregate->reconcile();
    expect($this->metrics->report('2026-10-01', '2026-10-31')['counts']['meetings'])->toBe(0);
});

it('abre evidencia de la cohorte aunque la acción se creara fuera del periodo', function () {
    $this->action->forceFill(['created_at' => '2026-09-20'])->save();
    $this->aggregate->reconcile();
    Livewire::test(Analytics::class)->set('from', '2026-10-01')->set('to', '2026-10-31')
        ->call('inspectMetric', 'executed')->assertSee('Indicador seleccionado:')
        ->assertViewHas('evidence', fn ($rows) => $rows->total() === 1 && $rows->first()->action_id === $this->action->id)
        ->call('inspectMetric', 'conversions_associated')->assertViewHas('evidence', fn ($rows) => $rows->total() === 1);
});

it('actualiza la proyección desde Livewire y confirma solo tras persistir', function () {
    $before = $this->action->fresh()->getAttributes();
    Livewire::test(Analytics::class)->call('reconcile')->assertHasNoErrors()
        ->assertSee('Proyección actualizada. No se modificaron datos comerciales ni políticas.');
    expect(AnalyticsFact::query()->where('kind', 'action')->count())->toBe(1)
        ->and(DB::table('follow_up_analytics_runs')->where('institution_id', $this->institution->id)->value('completed_at'))->not->toBeNull()
        ->and($this->action->fresh()->getAttributes())->toBe($before);
});
