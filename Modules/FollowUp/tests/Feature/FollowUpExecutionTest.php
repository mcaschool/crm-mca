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

function executionHttp(callable|array $callback): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

beforeEach(function () {
    Http::preventStrayRequests();
    executionHttp(['*' => Http::response(['messages' => [['id' => 'wamid.followup.test']]], 200)]);
    Queue::fake();
    config(['followup.policy_enabled' => true, 'followup.execution_enabled' => true, 'social.advisor.autoreply_enabled' => false]);
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $this->admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->create();
    $this->channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id, 'advisor_enabled' => true, 'is_active' => true, 'connection_status' => 'connected_cloud_api']);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $this->channel->id, 'provider' => 'whatsapp', 'contact_name' => 'Ana', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()->subHours(3)]);
    $last = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'provider_timestamp' => now()->subHours(2)]);
    $this->opportunity = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $this->channel->id, 'social_conversation_id' => $this->conversation->id, 'provider' => 'whatsapp', 'source_last_message_id' => $last->id, 'source_last_message_at' => now()->subHours(2), 'last_inbound_at' => now()->subHours(3), 'last_outbound_at' => now()->subHours(2), 'status' => 'open', 'contact_id' => null, 'lead_id' => null]);
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'sequence' => 1, 'classification' => 'general', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $this->setting = FollowUpSetting::query()->create(['enabled' => true, 'mode' => 'supervised', 'first_follow_up_hours' => 1, 'minimum_interval_hours' => 1, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'business_hours_start' => '00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC', 'allowed_channels' => ['whatsapp']]);
    FollowUpAdvisorSetting::query()->create(['bot_id' => $this->bot->id, 'enabled' => true]);
    app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'test_opt_in', 'test_evidence');
    $this->action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'draft', 'policy_status' => 'allowed', 'policy_reason_code' => 'schedule_allowed', 'policy_version' => '2.0', 'eligibility_version' => 2, 'idempotency_key' => hash('sha256', 'execution'), 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'scheduled_at' => now()->subMinute(), 'expires_at' => now()->addDay()]);
    executionDraft($this->action);
    $switches = app(ExecutionSwitches::class);
    foreach (['global' => 0, 'institution' => $institution->id, 'advisor' => $this->bot->id, 'channel' => $this->channel->id] as $scope => $id) {
        $switches->set($this->admin, $scope, $id, true, 10, 'test_only_capacity');
    }
    $this->executor = app(FollowUpExecutor::class);
    $this->executor->approve($this->admin, $this->action->id, 1);
});

function executionDraft(FollowUpAction $action): void
{
    $action->forceFill(['draft_state' => 'review', 'draft_version' => 1, 'draft_expires_at' => now()->addHours(12), 'draft_generated_at' => now(), 'draft_payload' => [
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar dudas', 'draft_text' => '¿Qué dudas te gustaría resolver?', 'language' => 'es', 'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta pendiente', 'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ]])->save();
    $action->forceFill(['context_fingerprint' => app(FollowUpDraftContext::class)->build($action)['fingerprint']])->save();
}

it('stage8 exige evidencia vigente aunque el consentimiento figure concedido', function () {
    \Modules\FollowUp\Models\CommunicationConsent::query()->update(['source' => null, 'evidence' => null]);
    $action = $this->action->fresh();
    $decision = app(\Modules\FollowUp\Services\FollowUpPolicyEngine::class)->evaluate(new \Modules\FollowUp\Data\PolicyRequest($action->opportunity, 'schedule', $action->channel, $action->purpose, $action->scheduled_at, $action->expires_at, actor: $this->admin, action: $action));
    expect($decision->allows())->toBeFalse()->and($decision->reasonCode)->toBe('consent_evidence_missing');
    Http::assertNothingSent();
});

it('stage8 impide preparar mensajes sin una acción reclamada y autorizada', function () {
    $count = SocialMessage::query()->count();
    expect(fn () => app(\Modules\Social\Services\SocialOutboundService::class)->prepareFollowUp($this->conversation, 'Texto no autorizado', $this->bot->id, $this->action->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(SocialMessage::query()->count())->toBe($count);
    Http::assertNothingSent();
});

it('stage8 impide despachar un mensaje sin intención de despacho vinculada', function () {
    $message = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'status' => 'pending', 'ai_meta' => ['follow_up_action_id' => $this->action->id]]);
    expect(fn () => app(\Modules\Social\Services\SocialOutboundService::class)->dispatchFollowUp($message))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    Http::assertNothingSent();
});

it('ejecuta una sola vez por el gateway sin transacción de red ni transferencia humana', function () {
    $level = DB::transactionLevel();
    executionHttp(function () use ($level) {
        expect(DB::transactionLevel())->toBe($level);
        $this->executor->execute($this->action->id);

        return Http::response(['messages' => [['id' => 'wamid.once']]], 200);
    });
    $this->executor->execute($this->action->id);
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('sent')->and(SocialMessage::query()->count())->toBe(3)
        ->and($this->conversation->fresh()->automation_state)->toBe('bot');
    Http::assertSentCount(1);
});

it('bloquea antes de crear mensajes cuando cambia una condición obligatoria', function (string $condition) {
    match ($condition) {
        'global' => config(['followup.execution_enabled' => false]),
        'institution' => app(ExecutionSwitches::class)->set($this->admin, 'institution', $this->admin->institution_id, false),
        'advisor' => app(ExecutionSwitches::class)->set($this->admin, 'advisor', $this->bot->id, false),
        'channel' => app(ExecutionSwitches::class)->set($this->admin, 'channel', $this->channel->id, false),
        'consent' => app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'withdrawn', 'test', 'withdraw'),
        'unknown_consent' => \Modules\FollowUp\Models\CommunicationConsent::query()->update(['state' => 'unknown']),
        'approval' => $this->action->forceFill(['approved_draft_version' => 0])->save(),
        'approver' => $this->admin->update(['status' => 'inactive']),
        'draft_expired' => $this->action->forceFill(['draft_expires_at' => now()->subSecond()])->save(),
        'context' => $this->bot->update(['tone' => 'Changed']),
        'inbound' => SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound']),
        'human' => $this->conversation->update(['automation_state' => 'human']),
        'disconnected' => $this->channel->update(['connection_status' => 'disconnected']),
        'hours' => $this->setting->update(['allowed_days' => []]),
        'window' => $this->opportunity->update(['last_inbound_at' => now()->subDays(2)]),
        'limit' => DB::table('follow_up_execution_switches')->where('scope', 'institution')->update(['daily_limit' => 0]),
        'capacity' => DB::table('follow_up_execution_switches')->where('scope', 'channel')->update(['verified_until' => now()->subSecond()]),
    };
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
    expect($this->action->fresh()->status)->toBeIn(['suppressed', 'cancelled'])->and($this->action->fresh()->social_message_id)->toBeNull();
})->with(['global', 'institution', 'advisor', 'channel', 'consent', 'unknown_consent', 'approval', 'approver', 'draft_expired', 'context', 'inbound', 'human', 'disconnected', 'hours', 'window', 'limit', 'capacity']);

it('preserva entrega desconocida sin reenvío y reconcilia confirmaciones tardías sin regresión', function () {
    executionHttp(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('delivery_unknown');
    $this->executor->execute($this->action->id);
    $message = SocialMessage::query()->findOrFail($this->action->fresh()->social_message_id);
    $message->update(['status' => 'read', 'external_message_id' => 'wamid.late']);
    $this->executor->reconcile($this->action->id);
    $message->update(['status' => 'sent']);
    $this->executor->reconcile($this->action->id);
    expect($this->action->fresh()->status)->toBe('read');
});

it('no degrada un webhook que llega antes de la respuesta HTTP', function () {
    executionHttp(function () {
        SocialMessage::query()->where('status', 'sending')->update(['status' => 'read', 'external_message_id' => 'wamid.early']);

        return Http::response(['messages' => [['id' => 'wamid.early']]], 200);
    });
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('read');
});

it('registra rechazos definitivos sin reintento ciego', function (int $code) {
    executionHttp(fn () => Http::response(['error' => ['message' => 'fake', 'code' => $code]], 400));
    $this->executor->execute($this->action->id);
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('failed');
    Http::assertSentCount(1);
})->with([190, 130429, 131047]);

it('envía plantilla vigente fuera de ventana sin interpolar el borrador IA', function () {
    $this->opportunity->update(['last_inbound_at' => now()->subDays(2)]);
    SocialMessage::query()->where('social_conversation_id', $this->conversation->id)->where('direction', 'inbound')->update(['provider_timestamp' => now()->subDays(2)]);
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $this->channel->id, 'category' => 'MARKETING', 'language' => 'es', 'last_synced_at' => now(), 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, ¿quieres retomar tu consulta?']]]);
    $this->action->update(['social_whatsapp_template_id' => $template->id]);
    executionDraft($this->action->refresh());
    $this->executor->approve($this->admin, $this->action->id, 1, [1 => 'contact_name']);
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('sent');
    Http::assertSent(fn ($r) => $r['type'] === 'template' && $r['template']['components'][0]['parameters'][0]['text'] === 'Ana');
});

it('recupera reservas y llamadas interrumpidas sin reenviar', function () {
    $this->action->forceFill(['status' => 'claimed', 'claimed_at' => now()->subMinutes(4)])->save();
    $this->executor->reconcile($this->action->id);
    expect($this->action->fresh()->execution_error)->toBe('claim_expired');
    $this->action->forceFill(['status' => 'sending', 'dispatch_at' => now()->subMinutes(4)])->save();
    $this->executor->reconcile($this->action->id);
    expect($this->action->fresh()->status)->toBe('delivery_unknown');
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
});

it('deduplica scheduler y respeta apagado al ejecutar jobs pendientes', function () {
    $this->artisan('followup:dispatch', ['--institution' => $this->admin->institution_id])->assertSuccessful();
    $this->artisan('followup:dispatch', ['--institution' => $this->admin->institution_id])->assertSuccessful();
    Queue::assertPushed(\Modules\FollowUp\Jobs\ExecuteFollowUp::class, 1);
    app(ExecutionSwitches::class)->set($this->admin, 'channel', $this->channel->id, false);
    (new \Modules\FollowUp\Jobs\ExecuteFollowUp($this->admin->institution_id, $this->action->id))->handle();
    Http::assertNothingSent();
});

it('aísla identificadores y rechaza aprobación de usuarios sin permiso', function () {
    $user = User::factory()->create(['institution_id' => $this->admin->institution_id, 'role' => 'marketing']);
    expect(fn () => $this->executor->approve($user, $this->action->id, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->set($other->id);
    $this->executor->execute($this->action->id);
    expect(fn () => $this->executor->approve($this->admin, $this->action->id, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('revalida cambios entre la reserva y el despacho', function (string $change) {
    \Illuminate\Support\Facades\Event::listen('eloquent.created: '.\Modules\FollowUp\Models\FollowUpEvent::class, function ($event) use ($change) {
        if ($event->event_type !== 'execution.claimed') {
            return;
        }
        match ($change) {
            'inbound' => SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound']),
            'human' => $this->conversation->update(['automation_state' => 'waiting_human']),
            'consent' => app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'withdrawn', 'test', 'withdraw'),
            'cancel' => app(\Modules\FollowUp\Services\FollowUpActionPlanner::class)->cancel($this->admin, $this->action->id),
            'shutdown' => app(ExecutionSwitches::class)->set($this->admin, 'global', 0, false),
        };
    });
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBeIn(['cancelled', 'suppressed']);
    Http::assertNothingSent();
})->with(['inbound', 'human', 'consent', 'cancel', 'shutdown']);

it('no simula cancelación de un mensaje después del punto de despacho', function () {
    executionHttp(function () {
        app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'withdrawn', 'test', 'withdraw');

        return Http::response(['messages' => [['id' => 'wamid.committed']]], 200);
    });
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('sent');
    app(\Modules\FollowUp\Services\FollowUpDraftService::class)->refreshValidity($this->action->fresh());
    expect($this->action->fresh()->approved_by_user_id)->toBe($this->admin->id);
    Http::assertSentCount(1);
});

it('rechaza plantillas no verificables aunque se hubieran aprobado anteriormente', function (string $change) {
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $this->channel->id, 'category' => 'MARKETING', 'language' => 'es', 'last_synced_at' => now(), 'components' => [['type' => 'BODY', 'text' => 'Retomamos tu consulta.']]]);
    $this->action->update(['social_whatsapp_template_id' => $template->id]);
    $this->executor->approve($this->admin, $this->action->id, 1);
    match ($change) {
        'rejected' => $template->update(['status' => 'REJECTED']),
        'category' => $template->update(['category' => 'UTILITY']),
        'language' => $template->update(['language' => 'en_US']),
        'stale' => $template->update(['last_synced_at' => now()->subHours(2)]),
        'variables' => $template->update(['components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']]]),
    };
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
    expect($this->action->fresh()->status)->toBe('suppressed');
})->with(['rejected', 'category', 'language', 'stale', 'variables']);

it('respeta revisión humana y aprobación de contenido en todos los modos', function (string $mode) {
    $this->setting->update(['mode' => $mode]);
    executionDraft($this->action->refresh());
    $this->executor->approve($this->admin, $this->action->id, 1);
    $this->action->forceFill(['approved_by_user_id' => null])->save();
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
})->with(['manual', 'supervised', 'controlled_auto']);

it('permite ejecución controlada con la aprobación requerida y coexistencia por Cloud API', function () {
    $this->setting->update(['mode' => 'controlled_auto']);
    $this->channel->update(['connection_status' => 'connected_coexistence']);
    executionDraft($this->action->refresh());
    $this->executor->approve($this->admin, $this->action->id, 1);
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('sent');
    Http::assertSentCount(1);
});

it('bloquea bajas y leads cerrados sin modificarlos', function (string $state) {
    $contact = \Modules\Crm\Models\Contact::factory()->create(['unsubscribed_at' => $state === 'unsubscribe' ? now() : null]);
    $lead = \Modules\Crm\Models\Lead::factory()->create(['contact_id' => $contact->id, 'status' => $state === 'unsubscribe' ? 'new' : $state]);
    $this->opportunity->update(['contact_id' => $contact->id, 'lead_id' => $lead->id]);
    $before = $lead->fresh()->getAttributes();
    $this->executor->execute($this->action->id);
    expect($lead->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
})->with(['enrolled', 'discarded', 'unsubscribe']);

it('vincula la aprobación a la previsualización y rechaza versión humana obsoleta', function () {
    expect(fn () => $this->executor->approve($this->admin, $this->action->id, 1, expectedContentHash: str_repeat('0', 64)))->toThrow(\Illuminate\Validation\ValidationException::class);
    app(\Modules\FollowUp\Services\FollowUpDraftService::class)->edit($this->admin, $this->action->id, 1, '¿Qué aspecto te interesa conocer?');
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
    expect($this->action->fresh()->approved_at)->toBeNull();
});

it('proyecta estados desde DeliveryReconciler sin volver a llamar al proveedor', function () {
    $this->executor->execute($this->action->id);
    $message = SocialMessage::query()->findOrFail($this->action->fresh()->social_message_id);
    $message->update(['status' => 'delivered']);
    app(\Modules\Social\Services\DeliveryReconciler::class)->run();
    app(\Modules\Social\Services\DeliveryReconciler::class)->run();
    expect($this->action->fresh()->status)->toBe('delivered');
    Http::assertSentCount(1);
});

it('trata aceptación sin identificador como desconocida en Inbox y FollowUp', function () {
    executionHttp(fn () => Http::response(['messages' => []], 200));
    $this->executor->execute($this->action->id);
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->status)->toBe('delivery_unknown')
        ->and(SocialMessage::query()->findOrFail($this->action->fresh()->social_message_id)->status)->toBe('delivery_unknown');
    Http::assertSentCount(1);
});

it('conserva la invalidación persistente cuando se intenta aprobar contenido obsoleto', function () {
    $this->bot->update(['tone' => 'Otro tono']);
    expect(fn () => $this->executor->approve($this->admin, $this->action->id, 1))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($this->action->fresh()->draft_state)->toBe('invalid');
});

it('no reinicia límites al abrir otro ciclo', function () {
    $past = $this->action->replicate(['idempotency_key']);
    $past->forceFill(['idempotency_key' => hash('sha256', 'past'), 'status' => 'sent', 'dispatch_at' => now()->subMinutes(5)])->save();
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
    expect($this->action->fresh()->execution_error)->toBe('recipient_cooldown');
});

it('no registra texto arbitrario de excepciones o respuestas del proveedor', function () {
    \Illuminate\Support\Facades\Log::spy();
    executionHttp(fn () => throw new \Illuminate\Http\Client\ConnectionException('secret=very-private Operation timed out'));
    $this->executor->execute($this->action->id);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'social.send.wa: error de red' && ! str_contains(json_encode($context), 'very-private'))->once();
});

it('muestra contenido definitivo y permite detener la institución desde Livewire', function () {
    $hash = app(\Modules\FollowUp\Services\ExecutionContent::class)->build($this->action->fresh())['hash'];
    \Livewire\Livewire::actingAs($this->admin)->test(\Modules\FollowUp\Livewire\Opportunities::class)
        ->call('selectOpportunity', $this->opportunity->id)->assertSee('Contenido definitivo para aprobar')
        ->call('approveExecution', $this->action->id, 1, $hash)->assertHasNoErrors()
        ->call('stopExecution')->assertHasNoErrors();
    $this->executor->execute($this->action->id);
    Http::assertNothingSent();
});

it('permite apagado global persistente y auditado desde el comando de administración', function () {
    $this->artisan('followup:execution-stop', ['scope' => 'global', 'subject' => 0, '--actor' => $this->admin->id, '--institution' => $this->admin->institution_id])->assertSuccessful();
    $this->executor->execute($this->action->id);
    expect($this->action->fresh()->execution_error)->toBe('execution_global_disabled');
    Http::assertNothingSent();
});

it('preserva el resultado y auditoría si la retención elimina el mensaje histórico', function () {
    $this->executor->execute($this->action->id);
    SocialMessage::query()->findOrFail($this->action->fresh()->social_message_id)->delete();
    $this->executor->reconcile($this->action->id);
    expect($this->action->fresh()->status)->toBe('sent')->and($this->action->fresh()->social_message_id)->toBeNull()
        ->and(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'execution.sent')->exists())->toBeTrue();
    $this->executor->execute($this->action->id);
    Http::assertSentCount(1);
});
