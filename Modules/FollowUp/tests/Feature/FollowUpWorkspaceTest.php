<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Livewire\Opportunities;
use Modules\FollowUp\Livewire\Settings;
use Modules\FollowUp\Livewire\Workspace;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\FollowUpDraftContext;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    config(['followup.policy_enabled' => true, 'followup.execution_enabled' => false, 'social.advisor.autoreply_enabled' => false]);
    $this->institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($this->institution->id);
    $this->admin = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->create(['assistant_name' => 'Asesor local']);
    $channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id, 'advisor_enabled' => true, 'is_active' => true, 'connection_status' => 'connected_cloud_api']);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'whatsapp', 'contact_name' => 'Prospecto de prueba', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'body' => 'Consulta local', 'provider_timestamp' => now()->subHours(3)]);
    $message = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'body' => 'Información de prueba', 'provider_timestamp' => now()->subHours(2)]);
    $this->op = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $channel->id, 'social_conversation_id' => $this->conversation->id, 'source_last_message_id' => $message->id, 'source_last_message_at' => now()->subHours(2), 'last_inbound_at' => now()->subHours(3), 'last_outbound_at' => now()->subHours(2), 'priority' => 'high']);
    FollowUpSetting::query()->create(['enabled' => true, 'mode' => 'supervised', 'first_follow_up_hours' => 1, 'minimum_interval_hours' => 1, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'business_hours_start' => '00:00', 'business_hours_end' => '23:59:59', 'timezone' => 'UTC', 'allowed_channels' => ['whatsapp']]);
    FollowUpAdvisorSetting::query()->create(['bot_id' => $this->bot->id, 'enabled' => true]);
    app(ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'granted', 'Prueba local', 'Registro de prueba');
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->op->id, 'sequence' => 1, 'classification' => 'general_interest', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $this->action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $this->op->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'pending_approval', 'policy_status' => 'allowed', 'policy_reason_code' => 'schedule_allowed', 'policy_version' => '2.0', 'eligibility_version' => 2, 'idempotency_key' => hash('sha256', 'workspace'), 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'scheduled_at' => now()->addHour(), 'expires_at' => now()->addHours(12)]);
    $this->action->forceFill(['draft_state' => 'review', 'draft_version' => 1, 'draft_generated_at' => now(), 'draft_expires_at' => now()->addHours(10), 'draft_payload' => ['draft_text' => '¿Qué información deseas revisar?', 'commercial_objective' => 'Resolver consulta', 'context_summary' => 'Consulta pendiente de aclaración', 'recommended_action' => 'draft_message', 'language' => 'es', 'source_references' => [], 'warnings' => [], 'reason_codes' => ['pending_question'], 'requires_human_review' => true]])->save();
    $this->action->forceFill(['context_fingerprint' => app(FollowUpDraftContext::class)->build($this->action)['fingerprint']])->save();
});

afterEach(function () {
    Http::assertNothingSent();
});

it('configura el asesor seleccionado con canales reales sin cambiar atención reactiva', function () {
    $channel = $this->conversation->channel;
    $before = $channel->getAttributes();
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)
        ->assertSee('Canales asignados y permiso de seguimiento')
        ->set('advisorMode', 'manual')->set('advisorFirstHours', 2)->set('advisorMinimumHours', 3)
        ->set('advisorMaximumAttempts', 2)->set('advisorAllowedDays', [1, 2])
        ->set('advisorHoursStart', '09:00')->set('advisorHoursEnd', '17:00')
        ->set('advisorChannelIds', [$channel->id])->call('saveAdvisor')->assertHasNoErrors();
    $saved = FollowUpAdvisorSetting::query()->where('bot_id', $this->bot->id)->sole();
    expect($saved->mode)->toBe('manual')->and($saved->allowed_channel_ids)->toBe([$channel->id])
        ->and($saved->minimum_interval_hours)->toBe(3)->and($saved->allowed_days)->toBe([1, 2])
        ->and($channel->fresh()->getAttributes())->toBe($before)
        ->and($this->action->fresh()->status)->toBe('cancelled')
        ->and(\Modules\Audit\Models\AuditLog::query()->where('action', 'follow_up.advisor_settings_changed')->exists())->toBeTrue();
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->assertSet('advisorMode', 'manual')->assertSet('advisorChannelIds', [$channel->id]);
    Queue::assertNothingPushed();
});

it('rechaza canales ajenos reasignados desconectados y proveedores bloqueados', function (string $kind) {
    $channel = $this->conversation->channel;
    if ($kind === 'foreign') {
        $institution = Institution::factory()->create();
        $channel = app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create());
    } elseif ($kind === 'assignment') {
        $channel->update(['advisor_bot_id' => Bot::factory()->create()->id]);
    } elseif ($kind === 'disconnected') {
        $channel->update(['connection_status' => 'disconnected']);
    } else {
        $channel->update(['provider' => $kind]);
    }
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorChannelIds', [$channel->id])
        ->call('saveAdvisor')->assertHasErrors('allowed_channel_ids');
    expect($this->action->fresh()->status)->toBe('pending_approval');
})->with(['foreign', 'assignment', 'disconnected', 'instagram', 'messenger']);

it('impide ampliar modo horario límites y pausa institucional desde solicitudes manipuladas', function (string $field, mixed $value, string $error) {
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set($field, $value)->call('saveAdvisor')->assertHasErrors($error);
})->with([
    ['advisorMode', 'controlled_auto', 'mode'], ['advisorMaximumAttempts', 4, 'maximum_attempts'],
    ['advisorFirstHours', 0, 'first_follow_up_hours'], ['advisorTimezone', 'America/New_York', 'timezone'],
    ['pauseOnHuman', false, 'pause_on_human'], ['advisorHoursStart', '18:00', 'business_hours_end'],
]);

it('mantiene modos individuales más restrictivos al cambiar los límites institucionales', function () {
    $setting = FollowUpSetting::query()->sole();
    $setting->update(['mode' => 'controlled_auto']);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorMode', 'controlled_auto')->call('saveAdvisor')->assertHasNoErrors();
    $resolver = app(\Modules\FollowUp\Services\FollowUpSettingsResolver::class);
    expect($resolver->effective($this->institution->id, $this->bot->id)->mode)->toBe('controlled_auto');
    $setting->update(['mode' => 'manual']);
    expect($resolver->effective($this->institution->id, $this->bot->id)->mode)->toBe('manual');
});

it('una selección vacía bloquea canales y una reasignación bloquea la evaluación', function () {
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorChannelIds', [])->call('saveAdvisor')->assertHasNoErrors();
    $engine = app(\Modules\FollowUp\Services\FollowUpPolicyEngine::class);
    $request = fn () => new \Modules\FollowUp\Data\PolicyRequest($this->op->fresh(), 'prepare', 'whatsapp', 'requested_follow_up', actor: $this->admin);
    expect($engine->evaluate($request())->reasonCode)->toBe('channel_not_selected');
    $this->conversation->channel->update(['advisor_bot_id' => Bot::factory()->create()->id]);
    expect($engine->evaluate($request())->reasonCode)->toBe('advisor_channel_changed');
});

it('cambiar asesor limpia campos y rechaza identificadores de otra institución', function () {
    $second = Bot::factory()->create(['assistant_name' => 'Otro asesor']);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorMaximumAttempts', 2)
        ->set('botId', (string) $second->id)->assertSet('advisorMaximumAttempts', null)->assertSet('advisorEnabled', false);
    $other = Institution::factory()->create();
    $foreign = app(CurrentInstitution::class)->runFor($other->id, fn () => Bot::factory()->create());
    expect(fn () => Livewire::test(Settings::class)->set('botId', (string) $foreign->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('guardar asesor no cancela oportunidades de otros asesores ni resultados despachados', function () {
    $otherOp = FollowUpOpportunity::factory()->create();
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $otherOp->id, 'sequence' => 1, 'classification' => 'general_interest', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    $otherAction = $this->action->replicate();
    $otherAction->fill(['follow_up_opportunity_id' => $otherOp->id, 'follow_up_cycle_id' => $cycle->id, 'idempotency_key' => hash('sha256', 'other-advisor')])->save();
    $this->action->update(['status' => 'delivery_unknown', 'dispatch_at' => now()]);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->call('saveAdvisor')->assertHasNoErrors();
    expect($otherAction->fresh()->status)->toBe('pending_approval')->and($this->action->fresh()->status)->toBe('delivery_unknown');
});

it('un asesor inactivo no puede habilitar reglas ni preparar acciones', function () {
    $this->bot->update(['status' => 'inactive']);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorEnabled', true)->set('advisorChannelIds', [])->call('saveAdvisor')->assertHasErrors('enabled');
    $decision = app(\Modules\FollowUp\Services\FollowUpPolicyEngine::class)->evaluate(new \Modules\FollowUp\Data\PolicyRequest($this->op->fresh(), 'prepare', 'whatsapp', 'requested_follow_up', actor: $this->admin));
    expect($decision->reasonCode)->toBe('advisor_inactive');
});

it('acepta los extremos exactos del horario y rechaza días fuera de la institución', function () {
    FollowUpSetting::query()->sole()->update(['business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'allowed_days' => [1, 2]]);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->set('advisorHoursStart', '09:00')->set('advisorHoursEnd', '18:00')
        ->call('saveAdvisor')->assertHasNoErrors()->set('advisorAllowedDays', [7])->call('saveAdvisor')->assertHasErrors('allowed_days');
});

it('la matriz de canales no añade una consulta por asesor o canal', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->assertOk();
    $base = count(DB::getQueryLog());
    SocialChannel::factory()->count(12)->create(['advisor_bot_id' => $this->bot->id]);
    DB::flushQueryLog();
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($base + 1);
    DB::disableQueryLog();
});

it('abre la configuración del asesor desde la oportunidad y conserva selección en la URL', function () {
    Livewire::withQueryParams(['oportunidad' => (string) $this->op->id])->test(Opportunities::class)
        ->assertSee(route('followup.settings', ['asesor' => $this->bot->id]), false);
    Livewire::withQueryParams(['asesor' => (string) $this->bot->id])->test(Settings::class)
        ->assertSet('botId', (string) $this->bot->id)->assertViewHas('selectedBot', fn ($bot) => $bot->id === $this->bot->id);
});

it('la matriz respeta restricciones heredadas por proveedor al cargar ajustes antiguos', function () {
    FollowUpAdvisorSetting::query()->where('bot_id', $this->bot->id)->update(['allowed_channels' => ['instagram']]);
    Livewire::test(Settings::class)->set('botId', (string) $this->bot->id)->assertSet('advisorChannelIds', [])
        ->call('saveAdvisor')->assertHasNoErrors();
    expect(FollowUpAdvisorSetting::query()->where('bot_id', $this->bot->id)->sole()->allowed_channel_ids)->toBe([]);
});

it('filtra historial por actor y ambos extremos de un mismo día', function () {
    $events = \Modules\FollowUp\Models\FollowUpEvent::query();
    $events->delete();
    foreach ([-1, 0, 1] as $offset) {
        $events->create(['actor_user_id' => $this->admin->id, 'actor_type' => 'user', 'event_type' => 'draft.edited', 'occurred_at' => now()->addDays($offset)]);
    }
    Livewire::withQueryParams(['vista' => 'history'])->test(Workspace::class)
        ->set('actor', $this->admin->name)->set('from', now()->toDateString())->set('to', now()->toDateString())
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
        ->set('actor', 'system')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
});

it('excluye borradores vacíos y distingue aprobación edición y caducidad', function () {
    $this->action->forceFill(['draft_state' => 'empty'])->save();
    Livewire::withQueryParams(['vista' => 'drafts'])->test(Workspace::class)->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    $this->action->forceFill(['draft_state' => 'review', 'draft_edited_at' => now()])->save();
    expect(\Modules\FollowUp\Support\Presentation::draftLabel($this->action))->toBe('Editado · requiere revisión');
    $this->action->forceFill(['approved_at' => now(), 'approved_draft_version' => 1, 'approval_expires_at' => now()->addHour()])->save();
    expect(\Modules\FollowUp\Support\Presentation::draftLabel($this->action))->toBe('Aprobado');
    $this->action->forceFill(['draft_expires_at' => now()->subMinute()])->save();
    expect(\Modules\FollowUp\Support\Presentation::draftLabel($this->action))->toBe('Caducado · requiere actualización');
});

it('muestra indicadores reales y navegación integrada sin mutaciones', function () {
    $before = $this->action->fresh()->getAttributes();
    Livewire::test(Workspace::class)->assertSee('Resumen operativo')->assertViewHas('counts', fn ($counts) => $counts['active'] === 1 && $counts['high'] === 1 && $counts['approval'] === 1 && $counts['scheduled'] === 0);
    expect($this->action->fresh()->getAttributes())->toBe($before);
    $this->get(route('followup.dashboard'))->assertOk()->assertSee('Seguimiento inteligente');
});

it('renderiza cada bandeja y sus estados vacíos', function (string $section) {
    Livewire::withQueryParams(['vista' => $section])->test(Workspace::class)->assertOk();
    Livewire::withQueryParams(['vista' => $section])->test(Workspace::class)->set('opportunity', '999999')->assertSee('No hay resultados con estos filtros');
})->with(['actions', 'drafts', 'human', 'history']);

it('filtra oportunidades por búsqueda aprobación consentimiento fecha y orden', function () {
    Livewire::test(Opportunities::class)->set('search', 'Prospecto')->set('approvalOnly', true)->set('consentOnly', true)->set('sort', 'recent')->assertSee('Prospecto de prueba')->set('search', 'No existe')->assertDontSee('Prospecto de prueba')->call('clearFilters')->assertSee('Prospecto de prueba')->set('from', now()->addDay()->toDateString())->assertDontSee('Prospecto de prueba');
});

it('abre detalle con enlace exacto a Inbox e historial sin duplicar mensajes', function () {
    $count = SocialMessage::query()->count();
    Livewire::withQueryParams(['oportunidad' => (string) $this->op->id])->test(Opportunities::class)->assertSee('Contexto reciente')->assertSee('Consulta local')->assertSee(route('social.inbox', ['conversacion' => $this->conversation->id]), false)->assertSee('Ver historial completo')->assertSee('Preparar un borrador')->assertSee('Pregunta pendiente');
    expect(SocialMessage::query()->count())->toBe($count);
    $this->get(route('social.inbox', ['conversacion' => $this->conversation->id]))->assertOk();
});

it('mantiene todas las operaciones administrativas protegidas para otros roles', function (string $role) {
    $this->admin->update(['role' => $role]);
    foreach ([Workspace::class, Opportunities::class, Settings::class] as $component) {
        Livewire::test($component)->assertForbidden();
    }
})->with(['marketing', 'admissions']);

it('rechaza usuarios desactivados al rehidratar una solicitud Livewire', function () {
    $component = Livewire::test(Opportunities::class);
    $this->admin->update(['status' => 'inactive']);
    $component->call('cancelAction', $this->action->id)->assertForbidden();
    expect($this->action->fresh()->status)->toBe('pending_approval');
});

it('impide lectura de oportunidades e inbox de otra institución', function () {
    $other = Institution::factory()->create();
    $foreign = app(CurrentInstitution::class)->runFor($other->id, fn () => FollowUpOpportunity::factory()->create());
    $this->get(route('followup.opportunities', ['oportunidad' => (string) $foreign->id]))->assertNotFound();
    $this->get(route('social.inbox', ['conversacion' => $foreign->social_conversation_id]))->assertNotFound();
});

it('aprueba solo la versión revisada y no despacha ni encola envíos', function () {
    $component = Livewire::test(Opportunities::class)->call('selectOpportunity', $this->op->id)->call('reviewExecution', $this->action->id)->assertSee('Contenido exacto')->assertSee('Consentimiento aplicable')->call('confirmExecution')->assertHasErrors('approvalConfirmed');
    $component->set('approvalConfirmed', true)->call('confirmExecution')->assertHasNoErrors()->assertSet('approvalId', 0);
    expect($this->action->fresh()->approved_draft_version)->toBe(1)->and($this->action->fresh()->status)->toBe('scheduled')->and($this->action->fresh()->dispatch_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('rechaza aprobación si otra edición cambió el contenido presentado', function () {
    $component = Livewire::test(Opportunities::class)->call('selectOpportunity', $this->op->id)->call('reviewExecution', $this->action->id);
    app(\Modules\FollowUp\Services\FollowUpDraftService::class)->edit($this->admin, $this->action->id, 1, '¿Te ayudo con otra información?');
    $component->set('approvalConfirmed', true)->call('confirmExecution')->assertHasErrors('execution');
    expect($this->action->fresh()->approved_at)->toBeNull();
});

it('no permite aprobar un borrador caducado y escapa contenido no confiable', function () {
    $this->conversation->update(['contact_name' => '<script>alert(1)</script>']);
    $this->action->forceFill(['draft_expires_at' => now()->subMinute()])->save();
    Livewire::test(Opportunities::class)->call('selectOpportunity', $this->op->id)->assertDontSee('<script>alert(1)</script>', false)->call('reviewExecution', $this->action->id)->assertHasErrors('execution');
});

it('pospone revalida e invalida aprobación y rechaza versiones obsoletas', function () {
    $this->action->forceFill(['status' => 'scheduled', 'approved_at' => now(), 'approved_draft_version' => 1])->save();
    $component = Livewire::test(Opportunities::class)->call('selectOpportunity', $this->op->id)->set('postponeAt', now()->addHours(2)->format('Y-m-d\TH:i'))->call('postponeAction', $this->action->id, 2)->assertHasNoErrors();
    expect($this->action->fresh()->status)->toBe('pending_approval')->and($this->action->fresh()->approved_at)->toBeNull()->and($this->action->fresh()->draft_state)->toBe('invalid');
    $component->call('postponeAction', $this->action->id, 2)->assertHasErrors('postponeAt');
});

it('explica resultado desconocido sin ofrecer reenvío', function () {
    $this->action->update(['status' => 'delivery_unknown']);
    Livewire::withQueryParams(['vista' => 'actions'])->test(Workspace::class)->assertSee('No reenviar')->assertSee('Resultado desconocido');
});

it('muestra configuración e interruptores sin habilitar ejecución', function () {
    Livewire::test(Settings::class)->assertSee('Envíos deshabilitados globalmente')->assertSee('Guardar reglas no activa envíos')->call('stopExecution')->assertHasNoErrors();
    expect(DB::table('follow_up_execution_switches')->where('scope', 'institution')->value('enabled'))->toBe(0);
});

it('mantiene consultas acotadas en la bandeja sin N más uno', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        if ($size === 20) {
            FollowUpOpportunity::factory()->count(19)->create();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(Opportunities::class)->assertOk();
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 3);
});

it('pagina oportunidades y prioriza la categoría antes de la puntuación', function () {
    FollowUpOpportunity::factory()->count(25)->create(['priority' => 'low', 'score' => 99]);
    Livewire::test(Opportunities::class)
        ->assertViewHas('opportunities', fn ($rows) => $rows->total() === 26 && $rows->count() === 25 && $rows->first()->id === $this->op->id)
        ->call('setPage', 2)->assertViewHas('opportunities', fn ($rows) => $rows->count() === 1);
});

it('excluye otras instituciones de las métricas y bloquea posponer un resultado ambiguo', function () {
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => FollowUpOpportunity::factory()->count(3)->create(['status' => 'open', 'priority' => 'high']));
    Livewire::test(Workspace::class)->assertViewHas('counts', fn ($counts) => $counts['active'] === 1 && $counts['high'] === 1);
    $this->action->update(['status' => 'delivery_unknown']);
    Livewire::test(Opportunities::class)->set('postponeAt', now()->addHours(2)->format('Y-m-d\TH:i'))->call('postponeAction', $this->action->id, 2)->assertHasErrors('postponeAt');
    expect($this->action->fresh()->status)->toBe('delivery_unknown');
});
