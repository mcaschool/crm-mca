<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Events\CommercialRecordChanged;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\LeadService;
use Modules\FollowUp\Livewire\Opportunities;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\FollowUpCommercialCoordinator;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Notification::fake();
    Queue::fake();
    config(['followup.execution_enabled' => false, 'social.advisor.autoreply_enabled' => false]);
    $this->institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($this->institution->id);
    $this->admin = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->create();
    $this->contact = Contact::factory()->phoneOnly('+57 300 111 2233')->create();
    $this->lead = Lead::factory()->create(['contact_id' => $this->contact->id, 'bot_id' => $this->bot->id, 'assigned_to_user_id' => $this->admin->id]);
    $channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id]);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'whatsapp', 'contact_external_id' => '573001112233', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    $this->message = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'body' => 'Quiero información', 'direction' => 'inbound']);
    $this->op = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $channel->id, 'social_conversation_id' => $this->conversation->id, 'provider' => 'whatsapp', 'classification' => 'commercial_interest', 'status' => 'open', 'lead_id' => null, 'contact_id' => null]);
    $this->coordinator = app(FollowUpCommercialCoordinator::class);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('asocia exclusivamente el teléfono exacto y recomienda sin mutar el lead', function () {
    $before = $this->lead->fresh()->getAttributes();
    $op = $this->coordinator->sync($this->op->id);
    expect($op->contact_id)->toBe($this->contact->id)->and($op->lead_id)->toBe($this->lead->id)
        ->and($op->commercial_owner_id)->toBe($this->admin->id)->and($op->commercial_recommendation['stage'])->toBe('suggest_contacted')
        ->and($this->lead->fresh()->getAttributes())->toBe($before);
    $again = $this->coordinator->sync($op->id);
    expect($again->commercial_version)->toBe($op->commercial_version)->and(FollowUpEvent::query()->where('event_type', 'commercial.synchronized')->count())->toBe(1);
});

it('no asocia nombres ni identificadores sociales incompatibles', function (string $provider) {
    $this->conversation->update(['provider' => $provider, 'contact_name' => $this->contact->name]);
    $op = $this->coordinator->sync($this->op->id);
    expect($op->contact_id)->toBeNull()->and($op->lead_id)->toBeNull()->and(Lead::query()->count())->toBe(1);
})->with(['instagram', 'messenger']);

it('deja pendiente el lead ambiguo sin crear otro', function () {
    Lead::factory()->create(['contact_id' => $this->contact->id, 'bot_id' => $this->bot->id]);
    $op = $this->coordinator->sync($this->op->id);
    expect($op->contact_id)->toBe($this->contact->id)->and($op->lead_id)->toBeNull()->and($op->commercial_recommendation['reason'])->toBe('ambiguous_lead')->and($op->commercial_hold)->toBeTrue();
});

it('permite oportunidades sin lead', function () {
    $this->lead->delete();
    $op = $this->coordinator->sync($this->op->id);
    expect($op->contact_id)->toBe($this->contact->id)->and($op->lead_id)->toBeNull()->and(Lead::query()->count())->toBe(0);
});

it('registra asociación manual cifrada con autor y versión', function () {
    $this->coordinator->link($this->admin, $this->op->id, 0, $this->contact->id, $this->lead->id, null, 'Verificado por expediente interno');
    $op = $this->op->fresh();
    expect($op->commercial_link_source)->toBe('manual')->and($op->commercial_linked_by)->toBe($this->admin->id)
        ->and($op->commercial_link_evidence)->toBe('Verificado por expediente interno')->and($op->getRawOriginal('commercial_link_evidence'))->not->toContain('Verificado');
    expect(fn () => $this->coordinator->link($this->admin, $op->id, 0, $this->contact->id, null, null, 'Cambio obsoleto'))->toThrow(ValidationException::class);
});

it('rechaza contacto de otra institución y no altera la oportunidad', function () {
    $other = Institution::factory()->create();
    $contact = app(CurrentInstitution::class)->runFor($other->id, fn () => Contact::factory()->create());
    expect(fn () => $this->coordinator->link($this->admin, $this->op->id, 0, $contact->id, null, null, 'Evidencia'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($this->op->fresh()->contact_id)->toBeNull();
});

it('crea una tarea idempotente sin invitados recordatorios ni notificaciones', function () {
    $owner = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->lead->update(['assigned_to_user_id' => $owner->id]);
    $op = $this->coordinator->sync($this->op->id);
    $activity = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $owner->id, 'meeting_proposal', now()->addDays(2)->toDateTimeString());
    $again = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $owner->id, 'meeting_proposal', now()->addDays(2)->toDateTimeString());
    expect($again->id)->toBe($activity->id)->and(Activity::query()->count())->toBe(1)->and($activity->type->value)->toBe('task')
        ->and($activity->assigned_to_user_id)->toBe($owner->id)->and($this->op->fresh()->status)->toBe('paused');
    Notification::assertNothingSent();
    expect(DB::table('crm_activity_guests')->count())->toBe(0)->and(DB::table('crm_activity_reminders')->count())->toBe(0);
});

it('sincroniza los resultados de agenda sin declarar conversión', function (string $method, string $code) {
    $op = $this->coordinator->sync($this->op->id);
    $activity = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(2)->toDateTimeString());
    app(ActivityService::class)->$method($activity, $this->admin);
    $op = $this->coordinator->sync($op->id);
    expect($op->commercial_result['code'])->toBe($code)->and($op->commercial_close_reason)->toBeNull()->and($op->status)->toBe('paused');
    $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, false);
    expect($this->op->fresh()->status)->toBe('open')->and($this->lead->fresh()->status)->toBe(LeadStatus::New);
})->with([['complete', 'activity_completed'], ['cancel', 'activity_cancelled'], ['markNoShow', 'activity_no_show']]);

it('cierra por estado confirmado y nunca reabre automáticamente', function (string $status, string $reason) {
    $this->coordinator->sync($this->op->id);
    app(LeadService::class)->changeStatus($this->lead, LeadStatus::from($status));
    $op = $this->coordinator->sync($this->op->id);
    expect($op->status)->toBe('closed')->and($op->commercial_close_reason)->toBe($reason);
    $this->lead->update(['status' => 'new']);
    expect($this->coordinator->sync($op->id)->status)->toBe('closed');
    expect(fn () => $this->coordinator->setHold($this->admin, $op->id, $this->op->fresh()->commercial_version, false))->toThrow(ValidationException::class);
})->with([['enrolled', 'converted'], ['discarded', 'discarded']]);

it('distingue intención e inferencia de matrícula confirmada', function (string $status) {
    $this->lead->update(['status' => $status]);
    $this->op->update(['classification' => 'enrollment_intent']);
    $op = $this->coordinator->sync($this->op->id);
    expect($op->commercial_recommendation['action'])->toBe('admissions_task')->and($op->commercial_recommendation['basis'])->toBe('inferred')
        ->and($op->commercial_close_reason)->toBeNull()->and($this->lead->fresh()->status->value)->toBe($status);
})->with(['new', 'contacted', 'qualified']);

it('bloquea exclusiones y conserva el cierre aunque se otorgue consentimiento', function () {
    $this->coordinator->sync($this->op->id);
    app(ConsentService::class)->record($this->admin, $this->contact->id, null, 'whatsapp', 'marketing', 'withdrawn', 'test', 'baja');
    $op = $this->coordinator->sync($this->op->id);
    expect($op->commercial_close_reason)->toBe('contact_excluded');
    app(ConsentService::class)->record($this->admin, $this->contact->id, null, 'whatsapp', 'marketing', 'granted', 'test', 'alta');
    expect($this->coordinator->sync($op->id)->status)->toBe('closed');
});

it('rechaza responsables inactivos y solicitudes sin permisos', function () {
    $op = $this->coordinator->sync($this->op->id);
    $this->admin->update(['status' => 'inactive']);
    expect(fn () => $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDay()->toDateTimeString()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Activity::query()->count())->toBe(0);
});

it('preserva intervención humana y rechaza una versión obsoleta', function () {
    $op = $this->coordinator->sync($this->op->id);
    $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, true);
    expect(fn () => $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, false))->toThrow(ValidationException::class);
    $this->conversation->update(['automation_state' => 'human']);
    $op = $this->coordinator->sync($op->id);
    expect($op->status)->toBe('paused');
    expect(fn () => $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, false))->toThrow(ValidationException::class);
});

it('reconcilia eventos perdidos y tolera eventos duplicados', function () {
    $this->coordinator->sync($this->op->id);
    $this->lead->update(['status' => 'enrolled']);
    $this->artisan('followup:commercial-sync', ['--institution' => $this->institution->id])->assertSuccessful();
    $version = $this->op->fresh()->commercial_version;
    $event = new CommercialRecordChanged($this->institution->id, 'lead', $this->lead->id);
    app(\Modules\FollowUp\Listeners\SyncCommercialContext::class)->handle($event);
    app(\Modules\FollowUp\Listeners\SyncCommercialContext::class)->handle($event);
    expect($this->op->fresh()->commercial_version)->toBe($version)->and($this->op->fresh()->commercial_close_reason)->toBe('converted');
});

it('muestra fichas originales y operaciones comerciales en el panel', function () {
    $this->coordinator->sync($this->op->id);
    Livewire::test(Opportunities::class)->call('selectOpportunity', $this->op->id)->assertSee('Continuidad comercial')->assertSee('Abrir Lead')->assertSee('Crear tarea interna en Agenda');
});

it('difiere eventos hasta commit y descarta proyecciones tras rollback', function () {
    $this->coordinator->sync($this->op->id);
    DB::beginTransaction();
    app(LeadService::class)->changeStatus($this->lead, LeadStatus::Enrolled);
    expect($this->op->fresh()->status)->toBe('open');
    DB::rollBack();
    expect($this->lead->fresh()->status)->toBe(LeadStatus::New)->and($this->op->fresh()->status)->toBe('open');
    DB::beginTransaction();
    app(LeadService::class)->changeStatus($this->lead->fresh(), LeadStatus::Enrolled);
    expect($this->op->fresh()->status)->toBe('open');
    DB::commit();
    expect($this->op->fresh()->commercial_close_reason)->toBe('converted');
});

function commercialAction(FollowUpOpportunity $op, string $status = 'scheduled'): \Modules\FollowUp\Models\FollowUpAction
{
    $cycle = \Modules\FollowUp\Models\FollowUpCycle::query()->firstOrCreate(['follow_up_opportunity_id' => $op->id, 'sequence' => $op->cycle], ['classification' => 'general_interest', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $action = \Modules\FollowUp\Models\FollowUpAction::query()->create(['follow_up_opportunity_id' => $op->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => $status, 'policy_status' => 'allowed', 'policy_reason_code' => 'test', 'policy_version' => '2.0', 'idempotency_key' => hash('sha256', uniqid('', true)), 'attempt_number' => 1, 'cycle_attempt_number' => 1]);
    $action->forceFill(['draft_state' => 'review', 'draft_version' => 1, 'draft_payload' => ['draft_text' => 'Borrador simulado']])->save();

    return $action;
}

it('invalida un envío programado y su borrador al confirmar conversión', function () {
    $op = $this->coordinator->sync($this->op->id);
    $action = commercialAction($op);
    app(LeadService::class)->changeStatus($this->lead, LeadStatus::Enrolled);
    expect($this->op->fresh()->commercial_close_reason)->toBe('converted')->and($action->fresh()->status)->toBe('cancelled')->and($action->fresh()->draft_state)->toBe('invalid');
    expect($action->cycle->end_reason)->toBe('converted')->and($action->cycle->ended_at)->not->toBeNull();
    app(\Modules\FollowUp\Services\FollowUpExecutor::class)->execute($action->id);
    expect(SocialMessage::query()->count())->toBe(1);
});

it('respeta límites acumulados entre ciclos y enfriamiento', function () {
    $op = $this->coordinator->sync($this->op->id);
    for ($i = 0; $i < 3; $i++) {
        commercialAction($op, 'sent');
    }
    $this->op->update(['cycle' => 2]);
    $op = $this->coordinator->sync($op->id);
    expect($op->commercial_recommendation['reason'])->toBe('maximum_attempts_reached')->and($op->status)->toBe('paused');
    $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, false);
    expect($this->op->fresh()->status)->toBe('paused');
});

it('espera el intervalo mínimo sin congelar permanentemente la oportunidad', function () {
    $this->op->update(['last_outbound_at' => now()]);
    $op = $this->coordinator->sync($this->op->id);
    expect($op->commercial_recommendation['action'])->toBe('no_action')->and($op->commercial_recommendation['reason'])->toBe('minimum_interval_not_met')->and($op->commercial_hold)->toBeFalse();
    $this->travel(25)->hours();
    expect($this->coordinator->sync($op->id)->commercial_recommendation['action'])->toBe('continue_conversation');
});

it('reanuda una solicitud humana revisada y vuelve a pausar ante otra señal', function () {
    $this->message->update(['body' => 'Quiero una reunión']);
    $op = $this->coordinator->sync($this->op->id);
    expect($op->commercial_recommendation['action'])->toBe('meeting_proposal')->and($op->status)->toBe('paused');
    $this->coordinator->setHold($this->admin, $op->id, $op->commercial_version, false);
    expect($this->op->fresh()->status)->toBe('open');
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'body' => 'Necesito hablar con un representante']);
    expect($this->coordinator->sync($op->id)->status)->toBe('paused');
});

it('rechaza actor sin permiso y responsables de otra institución inactivos o de lectura', function (string $case) {
    $op = $this->coordinator->sync($this->op->id);
    $owner = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    if ($case === 'actor') {
        $this->admin->update(['role' => 'admissions']);
    } elseif ($case === 'inactive') {
        $owner->update(['status' => 'inactive']);
    } elseif ($case === 'readonly') {
        $owner->update(['role' => 'marketing']);
    } else {
        $owner->update(['institution_id' => Institution::factory()->create()->id]);
    }
    expect(fn () => $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $owner->id, 'task', now()->addDays(2)->toDateTimeString()))->toThrow($case === 'tenant' ? \Illuminate\Database\Eloquent\ModelNotFoundException::class : \Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Activity::query()->count())->toBe(0);
})->with(['actor', 'inactive', 'readonly', 'tenant']);

it('conserva disponibilidad de agenda para actividades sin contacto equivalente', function () {
    $op = $this->coordinator->sync($this->op->id);
    $start = now()->addDays(2)->startOfMinute();
    app(ActivityService::class)->create(['title' => 'Otra obligación', 'type' => 'task', 'medium' => 'none', 'assigned_to_user_id' => $this->admin->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'timezone' => 'UTC', 'reminders' => []], $this->admin);
    expect(fn () => $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', $start->toDateTimeString()))->toThrow(\Modules\Crm\Services\Agenda\ScheduleConflictException::class);
    expect(Activity::query()->count())->toBe(1);
});

it('conserva respuesta y pausa si se completa la tarea al llegar un mensaje', function () {
    $op = $this->coordinator->sync($this->op->id);
    $activity = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(2)->toDateTimeString());
    app(ActivityService::class)->complete($activity, $this->admin);
    $message = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'body' => 'Gracias']);
    app(\Modules\FollowUp\Services\OpportunityDetector::class)->recordProspectResponse($this->conversation->id, $message->id);
    $op = $this->coordinator->sync($op->id);
    expect($op->status)->toBe('paused')->and($op->commercial_result['code'])->toBe('activity_completed')->and($op->cancellation_reason)->toBe('prospect_replied');
});

it('actualiza la próxima evaluación al reprogramar una actividad', function () {
    $op = $this->coordinator->sync($this->op->id);
    $activity = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(2)->toDateTimeString());
    $start = now()->addDays(3)->startOfMinute();
    app(ActivityService::class)->update($activity, ['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30)], $this->admin, rescheduleReason: 'Disponibilidad revisada');
    $op = $this->op->fresh();
    expect($op->next_evaluation_at->equalTo($start))->toBeTrue()->and($op->commercial_result['reschedule_count'])->toBe(1);
});

it('reutiliza una actividad existente y no crea otra equivalente', function () {
    $activity = app(ActivityService::class)->create(['title' => 'Revisar caso', 'type' => 'task', 'medium' => 'none', 'assigned_to_user_id' => $this->admin->id, 'contact_id' => $this->contact->id, 'lead_id' => $this->lead->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30), 'timezone' => 'UTC', 'reminders' => []], $this->admin);
    $op = $this->coordinator->sync($this->op->id);
    $result = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(3)->toDateTimeString());
    expect($result->id)->toBe($activity->id)->and(Activity::query()->count())->toBe(1);
});

it('bloquea tareas ante conflicto de responsables y cambios concurrentes', function () {
    $op = $this->coordinator->sync($this->op->id);
    $other = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->conversation->update(['assigned_to' => $other->id]);
    expect(fn () => $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(2)->toDateTimeString()))->toThrow(ValidationException::class);
    expect(Activity::query()->count())->toBe(0)->and($this->op->fresh()->commercial_owner_id)->toBeNull();
});

it('serializa dos procesos MySQL sin duplicar tareas ni evaluaciones', function (string $operation) {
    expect(app()->environment())->toBe('testing')->and(DB::connection()->getDatabaseName())->toBe('crm_mca_test');
    $op = $this->coordinator->sync($this->op->id);
    $initialEvents = FollowUpEvent::query()->count();
    // Child connections need committed fixtures. The finally block rebuilds only
    // the explicitly asserted disposable test database and restores test isolation.
    DB::commit();
    $workers = [];
    try {
        $db = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'crm_mca_test', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'APP_KEY' => config('app.key')];
        $at = (string) (microtime(true) + 3);
        for ($i = 0; $i < 2; $i++) {
            $mode = $operation === 'conversion' ? ($i === 0 ? 'racing_task' : 'convert') : $operation;
            $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('Modules/FollowUp/tests/Support/commercial-worker.php'), (string) $this->institution->id, (string) $this->admin->id, (string) $op->id, (string) $op->commercial_version, $at, $mode], base_path(), $env);
            $worker->start();
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        if ($operation !== 'conversion') {
            expect(trim($workers[0]->getOutput()))->toBe(trim($workers[1]->getOutput()));
        }
        if ($operation === 'conversion') {
            expect(Activity::query()->count())->toBeLessThanOrEqual(1)->and($this->op->fresh()->commercial_close_reason)->toBe('converted')->and($this->op->fresh()->status)->toBe('closed');
        } elseif ($operation === 'task') {
            expect(Activity::query()->count())->toBe(1)->and($this->op->fresh()->status)->toBe('paused');
        } else {
            expect(FollowUpEvent::query()->count())->toBe($initialEvents);
        }
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::beginTransaction();
    }
})->with(['sync', 'task', 'conversion']);

it('valida conversación CRM y conserva su intervención humana', function () {
    $conversation = \Modules\Crm\Models\Conversation::factory()->create(['contact_id' => $this->contact->id, 'bot_id' => $this->bot->id, 'is_test' => false, 'mode' => 'human']);
    $this->coordinator->link($this->admin, $this->op->id, 0, $this->contact->id, $this->lead->id, $conversation->id, 'Expediente verificado');
    expect($this->op->fresh()->crm_conversation_id)->toBe($conversation->id)->and($this->op->fresh()->status)->toBe('paused');
    $conversation->update(['is_test' => true]);
    expect(fn () => $this->coordinator->link($this->admin, $this->op->id, $this->op->fresh()->commercial_version, $this->contact->id, $this->lead->id, $conversation->id, 'Expediente'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('rechaza lead de otro contacto e invalida borradores al asociar manualmente', function () {
    $op = $this->coordinator->sync($this->op->id);
    $action = commercialAction($op);
    $other = Lead::factory()->create(['bot_id' => $this->bot->id]);
    expect(fn () => $this->coordinator->link($this->admin, $op->id, $op->commercial_version, $this->contact->id, $other->id, null, 'Expediente'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->coordinator->link($this->admin, $op->id, $op->commercial_version, $this->contact->id, $this->lead->id, null, 'Expediente verificado');
    expect($action->fresh()->draft_state)->toBe('invalid')->and($action->fresh()->status)->toBe('cancelled');
});

it('no elimina una referencia histórica al cerrar y rechaza referencias inexistentes', function () {
    $op = $this->coordinator->sync($this->op->id);
    $activity = $this->coordinator->createTask($this->admin, $op->id, $op->commercial_version, $this->admin->id, 'task', now()->addDays(2)->toDateTimeString());
    app(LeadService::class)->changeStatus($this->lead, LeadStatus::Enrolled);
    expect($this->op->fresh()->commercial_activity_id)->toBe($activity->id)->and(Activity::query()->find($activity->id))->not->toBeNull();
    expect(fn () => DB::transaction(fn () => $this->op->forceFill(['commercial_activity_id' => 999999999])->save()))->toThrow(\Illuminate\Database\QueryException::class);
});
