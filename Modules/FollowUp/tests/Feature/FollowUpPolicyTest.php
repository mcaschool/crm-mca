<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Livewire\Settings as FollowUpSettingsPage;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\CommunicationConsentEvent;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\ActionMaintenanceService;
use Modules\FollowUp\Services\ConsentService;
use Modules\FollowUp\Services\FollowUpActionPlanner;
use Modules\FollowUp\Services\FollowUpPolicyEngine;
use Modules\FollowUp\Services\FollowUpSettingsResolver;
use Modules\FollowUp\Services\FollowUpSettingsService;
use Modules\FollowUp\Services\OpportunityDetector;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;
use Modules\Social\Services\SocialAutomationService;

beforeEach(function () {
    config(['followup.policy_enabled' => true, 'social.advisor.autoreply_enabled' => false]);
});

/** @return array<string,mixed> */
function stageTwoFixture(string $provider = 'whatsapp'): array
{
    $institution = Institution::factory()->create(['timezone' => 'America/New_York']);

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $provider): array {
        $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
        $bot = Bot::factory()->create();
        $channel = SocialChannel::factory()->create(['provider' => $provider, 'advisor_bot_id' => $bot->id, 'advisor_enabled' => true, 'is_active' => true]);
        $conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => $provider, 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open', 'last_message_at' => now()->subHours(26)]);
        $inbound = SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'inbound', 'sender_type' => 'contact', 'provider_timestamp' => now()->subHours(30), 'created_at' => now()->subHours(30)]);
        $outbound = SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'provider_timestamp' => now()->subHours(26), 'created_at' => now()->subHours(26)]);
        $contact = Contact::factory()->create();
        $opportunity = FollowUpOpportunity::factory()->create([
            'bot_id' => $bot->id, 'social_channel_id' => $channel->id, 'social_conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'source_last_message_id' => $outbound->id, 'provider' => $provider,
            'last_inbound_at' => $inbound->provider_timestamp, 'last_outbound_at' => $outbound->provider_timestamp,
            'source_last_message_at' => $outbound->provider_timestamp, 'status' => 'open', 'cycle' => 1,
        ]);
        $cycle = FollowUpCycle::query()->create([
            'institution_id' => $institution->id, 'follow_up_opportunity_id' => $opportunity->id, 'sequence' => 1, 'status' => 'open',
            'classification' => $opportunity->classification, 'priority' => $opportunity->priority, 'score' => $opportunity->score,
            'reason_codes' => $opportunity->reason_codes, 'context_snapshot' => $opportunity->context_snapshot,
            'source_first_message_id' => $outbound->id, 'source_last_message_id' => $outbound->id, 'previous_attempts' => 0,
            'started_at' => now()->subHours(26), 'detected_at' => now()->subHours(24),
        ]);
        $setting = FollowUpSetting::query()->create([
            'institution_id' => $institution->id, 'enabled' => true, 'mode' => 'manual', 'first_follow_up_hours' => 24,
            'minimum_interval_hours' => 24, 'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5],
            'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'America/New_York',
            'allowed_channels' => [$provider], 'policy_version' => '2.0',
        ]);
        $advisor = FollowUpAdvisorSetting::query()->create(['institution_id' => $institution->id, 'bot_id' => $bot->id, 'enabled' => true]);
        $template = $provider === 'whatsapp' ? SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $channel->id, 'category' => 'MARKETING']) : null;

        return compact('institution', 'admin', 'bot', 'channel', 'conversation', 'inbound', 'outbound', 'contact', 'opportunity', 'cycle', 'setting', 'advisor', 'template');
    });
}

function stageTwoSchedule(): CarbonImmutable
{
    $date = CarbonImmutable::now('America/New_York')->addDay()->setTime(10, 0);
    while ($date->isWeekend()) {
        $date = $date->addDay();
    }

    return $date->utc();
}

it('usa valores seguros y no persiste ni activa configuración al resolverla', function () {
    $institution = Institution::factory()->create(['timezone' => 'America/New_York']);
    app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution): void {
        $setting = app(FollowUpSettingsResolver::class)->institution((int) $institution->id);
        expect($setting->exists)->toBeFalse()->and($setting->enabled)->toBeFalse()->and($setting->mode)->toBe('manual')
            ->and(FollowUpSetting::query()->count())->toBe(0);
    });
});

it('impide que un asesor relaje límites institucionales y audita cambios válidos', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $service = app(FollowUpSettingsService::class);

    expect(fn () => $service->saveAdvisor($data['admin'], $data['bot'], [
        'enabled' => true, 'minimum_interval_hours' => 1, 'pause_on_human' => true,
    ]))->toThrow(ValidationException::class);

    $service->saveAdvisor($data['admin'], $data['bot'], [
        'enabled' => true, 'minimum_interval_hours' => 48, 'maximum_attempts' => 2, 'pause_on_human' => true,
    ]);
    $effective = app(FollowUpSettingsResolver::class)->effective($data['institution']->id, $data['bot']->id);
    expect($effective->minimumIntervalHours)->toBe(48)
        ->and($effective->maximumAttempts)->toBe(2)
        ->and(AuditLog::query()->where('action', 'follow_up.advisor_settings_changed')->exists())->toBeTrue();
});

it('exige origen y evidencia y conserva historial transaccional del consentimiento', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $service = app(ConsentService::class);
    expect(fn () => $service->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', null, null))->toThrow(ValidationException::class);

    $service->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $service->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'withdrawn', 'inbound_request', 'mensaje #XYZ');
    expect(CommunicationConsent::query()->sole()->state)->toBe('withdrawn')
        ->and(CommunicationConsentEvent::query()->pluck('to_state')->all())->toBe(['granted', 'withdrawn']);
});

it('aplica consentimiento por canal y finalidad exactos y unknown nunca autoriza', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $at = stageTwoSchedule();
    $request = new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']);
    expect(app(FollowUpPolicyEngine::class)->evaluate($request)->status)->toBe('unknown');
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'marketing', 'granted', 'form', 'formulario #ABC');
    expect(app(FollowUpPolicyEngine::class)->evaluate($request)->reasonCode)->toBe('consent_unknown');
});

it('bloquea estados explícitos negativos y hace prevalecer la exclusión del contacto', function (string $state, string $reason) {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', $state, 'inbound_request', 'mensaje #ABC');
    $at = stageTwoSchedule();
    $request = new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']);
    expect(app(FollowUpPolicyEngine::class)->evaluate($request)->reasonCode)->toBe($reason);
})->with([
    'denegado' => ['denied', 'consent_denied'],
    'retirado' => ['withdrawn', 'consent_withdrawn'],
]);

it('una baja anterior prevalece incluso sobre un consentimiento concedido', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $data['contact']->forceFill(['unsubscribed_at' => now()])->save();
    $at = stageTwoSchedule();
    $request = new PolicyRequest($data['opportunity']->fresh(), 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']);
    expect(app(FollowUpPolicyEngine::class)->evaluate($request)->reasonCode)->toBe('contact_unsubscribed');
});

it('WhatsApp requiere plantilla aprobada fuera de 24 horas y permite programar localmente al cumplir todo', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $engine = app(FollowUpPolicyEngine::class);
    $without = $engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), actor: $data['admin']));
    $with = $engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']));
    expect($without->reasonCode)->toBe('whatsapp_template_required')->and($with->status)->toBe('allowed');
});

it('WhatsApp rechaza plantillas no aprobadas o de categoría incompatible', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $pending = SocialWhatsAppTemplate::factory()->pending()->create(['social_channel_id' => $data['channel']->id, 'category' => 'MARKETING']);
    $utility = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $data['channel']->id, 'category' => 'UTILITY']);
    $at = stageTwoSchedule();
    $engine = app(FollowUpPolicyEngine::class);
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $pending->id, $data['admin']))->reasonCode)->toBe('whatsapp_template_not_approved')
        ->and($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $utility->id, $data['admin']))->reasonCode)->toBe('whatsapp_template_category_mismatch');
});

it('mantiene Instagram y el contrato final de envío en estado no autorizante', function () {
    $data = stageTwoFixture('instagram');
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'instagram', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $engine = app(FollowUpPolicyEngine::class);
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'instagram', 'requested_follow_up', $at, $at->addDays(5), actor: $data['admin']))->status)->toBe('unknown')
        ->and($engine->evaluate(new PolicyRequest($data['opportunity'], 'send', 'instagram', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('execution_not_implemented');
});

it('crea una sola acción idempotente, acumula intentos y no crea mensajes', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $planner = app(FollowUpActionPlanner::class);
    $messages = SocialMessage::query()->count();
    $first = $planner->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    $second = $planner->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    expect($first->id)->toBe($second->id)->and($first->status)->toBe('pending_approval')->and(FollowUpAction::query()->count())->toBe(1)
        ->and(SocialMessage::query()->count())->toBe($messages);
});

it('invalida acciones ante retirada, respuesta e intervención humana', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $consent = app(ConsentService::class);
    $consent->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $action = app(FollowUpActionPlanner::class)->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    $consent->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'withdrawn', 'inbound_request', 'mensaje #XYZ');
    expect($action->fresh()->status)->toBe('cancelled')->and($action->fresh()->block_reason)->toBe('consent_changed');

    $action->forceFill(['status' => 'scheduled', 'cancelled_at' => null, 'block_reason' => null])->save();
    app(SocialAutomationService::class)->takeOver($data['conversation'], $data['admin']);
    expect($action->fresh()->status)->toBe('cancelled')->and($action->fresh()->block_reason)->toBe('conversation_human');
});

it('invalida acciones activas al cambiar la configuración institucional', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $action = app(FollowUpActionPlanner::class)->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    app(FollowUpSettingsService::class)->saveInstitution($data['admin'], [
        'enabled' => true, 'mode' => 'manual', 'first_follow_up_hours' => 24, 'minimum_interval_hours' => 48,
        'maximum_attempts' => 3, 'allowed_days' => [1, 2, 3, 4, 5], 'business_hours_start' => '09:00',
        'business_hours_end' => '18:00', 'timezone' => 'America/New_York', 'allowed_channels' => ['whatsapp'], 'policy_version' => '2.1',
    ]);
    expect($action->fresh()->status)->toBe('cancelled')
        ->and($action->fresh()->block_reason)->toBe('institution_settings_changed');
});

it('aísla consentimiento, acciones y eventos por institución', function () {
    $first = stageTwoFixture();
    $second = stageTwoFixture();
    app(CurrentInstitution::class)->set($first['institution']->id);
    $this->actingAs($first['admin']);
    app(ConsentService::class)->record($first['admin'], $first['contact']->id, null, 'whatsapp', 'requested_follow_up', 'denied', 'inbound_request', 'mensaje #1');
    expect(CommunicationConsent::query()->count())->toBe(1)->and(FollowUpEvent::query()->where('event_type', 'consent.changed')->count())->toBe(1);
    app(CurrentInstitution::class)->set($second['institution']->id);
    expect(CommunicationConsent::query()->count())->toBe(0)->and(FollowUpEvent::query()->count())->toBe(0);
});

it('la base impide consentimientos ambiguos y acciones duplicadas bajo reclamación repetida', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    expect(fn () => CommunicationConsent::query()->create([
        'institution_id' => $data['institution']->id, 'contact_id' => $data['contact']->id, 'social_conversation_id' => $data['conversation']->id,
        'subject_key' => 'ambiguous', 'channel' => 'whatsapp', 'purpose' => 'marketing', 'state' => 'unknown', 'policy_version' => '2.0',
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    FollowUpAction::query()->create([
        'institution_id' => $data['institution']->id, 'follow_up_opportunity_id' => $data['opportunity']->id, 'follow_up_cycle_id' => $data['cycle']->id,
        'channel' => 'whatsapp', 'purpose' => 'marketing', 'status' => 'draft', 'policy_status' => 'unknown', 'policy_reason_code' => 'test',
        'policy_version' => '2.0', 'idempotency_key' => str_repeat('a', 64), 'attempt_number' => 1, 'cycle_attempt_number' => 1,
    ]);
    expect(fn () => FollowUpAction::query()->create([
        'institution_id' => $data['institution']->id, 'follow_up_opportunity_id' => $data['opportunity']->id, 'follow_up_cycle_id' => $data['cycle']->id,
        'channel' => 'whatsapp', 'purpose' => 'marketing', 'status' => 'draft', 'policy_status' => 'unknown', 'policy_reason_code' => 'test',
        'policy_version' => '2.0', 'idempotency_key' => str_repeat('a', 64), 'attempt_number' => 2, 'cycle_attempt_number' => 2,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('bloquea preparación con feature flag global apagado y reserva configuración a administradores', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    config(['followup.policy_enabled' => false]);
    $decision = app(FollowUpPolicyEngine::class)->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']));
    expect($decision->reasonCode)->toBe('global_feature_disabled');

    $nonAdmin = User::factory()->create(['institution_id' => $data['institution']->id, 'role' => 'marketing', 'status' => 'active']);
    Livewire::actingAs($nonAdmin)->test(FollowUpSettingsPage::class)->assertForbidden();

    config(['followup.policy_enabled' => true]);
    Livewire::actingAs($data['admin'])->test(FollowUpSettingsPage::class)->assertOk()->assertSee('Políticas de seguimiento');
});

it('aplica horarios, intervalos y límites acumulados antes de programar', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $engine = app(FollowUpPolicyEngine::class);
    $at = stageTwoSchedule();

    $outside = $at->setTimezone('America/New_York')->setTime(20, 0)->utc();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $outside, $outside->addDays(5), $data['template']->id, $data['admin']))->reasonCode)->toBe('outside_business_hours');

    $data['setting']->forceFill(['first_follow_up_hours' => 168])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(10), $data['template']->id, $data['admin']))->reasonCode)->toBe('first_follow_up_not_due');

    $data['setting']->forceFill(['first_follow_up_hours' => 24])->save();
    $data['setting']->forceFill(['minimum_interval_hours' => 72])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']))->reasonCode)->toBe('minimum_interval_not_met');

    $data['setting']->forceFill(['minimum_interval_hours' => 24])->save();
    foreach (range(1, 3) as $attempt) {
        FollowUpAction::query()->create([
            'institution_id' => $data['institution']->id, 'follow_up_opportunity_id' => $data['opportunity']->id, 'follow_up_cycle_id' => $data['cycle']->id,
            'channel' => 'whatsapp', 'purpose' => 'operational', 'status' => 'expired', 'policy_status' => 'allowed', 'policy_reason_code' => 'test',
            'policy_version' => '2.0', 'idempotency_key' => hash('sha256', 'attempt'.$attempt), 'attempt_number' => $attempt, 'cycle_attempt_number' => $attempt,
        ]);
    }
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'schedule', 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id, $data['admin']))->reasonCode)->toBe('maximum_attempts_reached');
});

it('bloquea canal desconectado y Lead cerrado sin modificar ninguno', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $engine = app(FollowUpPolicyEngine::class);
    $data['channel']->forceFill(['is_active' => false])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('channel_unavailable');

    $data['channel']->forceFill(['is_active' => true])->save();
    $data['channel']->forceFill(['connection_status' => 'disconnected'])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('channel_unavailable');

    $data['channel']->forceFill(['connection_status' => 'connected_cloud_api'])->save();
    $lead = Lead::factory()->create(['contact_id' => $data['contact']->id, 'bot_id' => $data['bot']->id, 'status' => 'enrolled']);
    $data['opportunity']->forceFill(['lead_id' => $lead->id])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity']->fresh(), 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('lead_closed')
        ->and($lead->fresh()->status->value)->toBe('enrolled');
});

it('mantiene desconocidos los modos y zonas horarias que no tienen política válida', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $engine = app(FollowUpPolicyEngine::class);

    $data['setting']->forceFill(['mode' => 'supervised'])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->status)->toBe('allowed');

    $data['setting']->forceFill(['mode' => 'legacy'])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('operating_mode_unknown');

    $data['setting']->forceFill(['mode' => 'manual', 'timezone' => 'Mars/Olympus'])->save();
    expect($engine->evaluate(new PolicyRequest($data['opportunity'], 'prepare', 'whatsapp', 'requested_follow_up', actor: $data['admin']))->reasonCode)->toBe('timezone_unknown');
});

it('aprueba, programa, pausa y cancela únicamente estados locales', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $planner = app(FollowUpActionPlanner::class);
    $action = $planner->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    expect($planner->approve($data['admin'], $action->id)->status)->toBe('approved')
        ->and($planner->schedule($data['admin'], $action->id)->status)->toBe('scheduled')
        ->and($planner->pause($data['admin'], $action->id)->status)->toBe('paused')
        ->and($planner->cancel($data['admin'], $action->id)->status)->toBe('cancelled');
});

it('no revive una acción si el prospecto responde entre aprobación y programación', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    app(ConsentService::class)->record($data['admin'], $data['contact']->id, null, 'whatsapp', 'requested_follow_up', 'granted', 'form', 'formulario #ABC');
    $at = stageTwoSchedule();
    $planner = app(FollowUpActionPlanner::class);
    $action = $planner->propose($data['admin'], $data['opportunity']->id, 'whatsapp', 'requested_follow_up', $at, $at->addDays(5), $data['template']->id);
    $planner->approve($data['admin'], $action->id);
    $reply = SocialMessage::factory()->create([
        'social_conversation_id' => $data['conversation']->id,
        'direction' => 'inbound',
        'sender_type' => 'contact',
        'provider_timestamp' => now(),
    ]);
    app(OpportunityDetector::class)->recordProspectResponse($data['conversation']->id, $reply->id);

    expect(fn () => $planner->schedule($data['admin'], $action->id))->toThrow(ValidationException::class)
        ->and($action->fresh()->status)->toBe('cancelled')
        ->and($action->fresh()->block_reason)->toBe('prospect_replied');
});

it('caduca propuestas y reevalúa contexto obsoleto al reiniciar el scheduler', function () {
    $data = stageTwoFixture();
    app(CurrentInstitution::class)->set($data['institution']->id);
    $this->actingAs($data['admin']);
    $action = FollowUpAction::query()->create([
        'institution_id' => $data['institution']->id, 'follow_up_opportunity_id' => $data['opportunity']->id, 'follow_up_cycle_id' => $data['cycle']->id,
        'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'draft', 'scheduled_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        'policy_status' => 'unknown', 'policy_reason_code' => 'consent_unknown', 'policy_version' => '2.0', 'idempotency_key' => hash('sha256', 'expired'),
        'attempt_number' => 1, 'cycle_attempt_number' => 1,
    ]);
    $result = app(ActionMaintenanceService::class)->run();
    $repeated = app(ActionMaintenanceService::class)->run();
    expect($result['expired'])->toBe(1)
        ->and($repeated['expired'])->toBe(0)
        ->and($action->fresh()->status)->toBe('expired');
});

it('rechaza sujetos de consentimiento de otra institución', function () {
    $first = stageTwoFixture();
    $second = stageTwoFixture();
    app(CurrentInstitution::class)->set($first['institution']->id);
    $this->actingAs($first['admin']);
    expect(fn () => app(ConsentService::class)->record($first['admin'], $second['contact']->id, null, 'whatsapp', 'marketing', 'denied', 'form', 'evidencia'))
        ->toThrow(ValidationException::class);
});
