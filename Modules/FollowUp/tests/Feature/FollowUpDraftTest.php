<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiChatResponse;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\FollowUp\Services\FollowUpDraftService;
use Modules\FollowUp\Services\FollowUpDraftValidator;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

function draftOutput(array $override = []): string
{
    return json_encode(array_replace([
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar la duda pendiente',
        'draft_text' => '¿Qué aspecto del programa te gustaría aclarar?', 'language' => 'es',
        'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta sobre el programa.',
        'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ], $override), JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['followup.policy_enabled' => true]);
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $this->admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    $this->actingAs($this->admin);
    $this->bot = Bot::factory()->withOwnIdentity(['assistant_name' => 'Sofía', 'tone' => 'Cercano'])->create();
    $channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'advisor_bot_id' => $this->bot->id, 'advisor_enabled' => true, 'is_active' => true]);
    $this->conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'whatsapp', 'automation_state' => 'bot', 'assigned_to' => null, 'status' => 'open']);
    SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'body' => '¿Cómo es el programa?', 'sender_type' => 'contact']);
    $last = SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'body' => '¿Qué necesitas conocer?', 'sender_type' => 'bot']);
    $this->opportunity = FollowUpOpportunity::factory()->create(['bot_id' => $this->bot->id, 'social_channel_id' => $channel->id, 'social_conversation_id' => $this->conversation->id, 'provider' => 'whatsapp', 'source_last_message_id' => $last->id, 'source_last_message_at' => now(), 'status' => 'open', 'cycle' => 1, 'contact_id' => null, 'lead_id' => null]);
    $cycle = FollowUpCycle::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'sequence' => 1, 'classification' => 'general', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
    FollowUpSetting::query()->create(['enabled' => true, 'allowed_days' => [1, 2, 3, 4, 5, 6, 7], 'allowed_channels' => ['whatsapp']]);
    FollowUpAdvisorSetting::query()->create(['bot_id' => $this->bot->id, 'enabled' => true]);
    $this->action = FollowUpAction::query()->create(['follow_up_opportunity_id' => $this->opportunity->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'draft', 'policy_status' => 'unknown', 'policy_reason_code' => 'consent_unknown', 'policy_version' => '2.0', 'idempotency_key' => hash('sha256', 'draft'), 'attempt_number' => 1, 'cycle_attempt_number' => 1, 'expires_at' => now()->addDays(2)]);
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $this->bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $this->client = Mockery::mock(AiChatClient::class);
    app()->instance(AiChatClient::class, $this->client);
});

it('genera con identidad existente y persiste cifrado sin crear mensajes', function () {
    $transactionLevel = DB::transactionLevel();
    $this->client->shouldReceive('chat')->once()->withArgs(function ($integration, $model, $messages, $params, $context) use ($transactionLevel) {
        expect(DB::transactionLevel())->toBe($transactionLevel);
        expect($messages[0]['content'])->toContain('Sofía', 'Cercano')->and($context->process)->toBe('followup_draft');

        return true;
    })->andReturn(new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus', 50, 20, 10));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    expect($action->draft_state)->toBe('queued');
    $service->generate($action->id, $action->draft_reservation);
    $action->refresh();
    expect($action->draft_state)->toBe('review')->and($action->draft_payload['draft_text'])->toContain('aspecto')
        ->and(DB::table('follow_up_actions')->where('id', $action->id)->value('draft_payload'))->not->toContain('aspecto')
        ->and(SocialMessage::query()->count())->toBe(2);
    Http::assertNothingSent();
});

it('reserva una sola generación y descarta entregas tardías al cancelar', function () {
    $service = app(FollowUpDraftService::class);
    $first = $service->request($this->admin, $this->action->id, 0);
    $second = $service->request($this->admin, $this->action->id, 0);
    expect($first->draft_reservation)->toBe($second->draft_reservation);
    $this->client->shouldReceive('chat')->once()->andReturnUsing(function () use ($service, $first) {
        $service->generate($first->id, $first->draft_reservation);
        $service->discard($this->admin, $first->id, $first->draft_version);

        return new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus');
    });
    $service->generate($first->id, $first->draft_reservation);
    $service->generate($first->id, $first->draft_reservation);
    expect($first->fresh()->draft_state)->toBe('invalid')->and($first->fresh()->draft_payload)->toBeNull();
});

it('invalida la respuesta del proveedor si cambia el contexto durante la llamada', function (string $change) {
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $this->client->shouldReceive('chat')->once()->andReturnUsing(function () use ($change) {
        if ($change === 'reply') {
            SocialMessage::factory()->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'inbound']);
        } elseif ($change === 'human') {
            $this->conversation->update(['automation_state' => 'human']);
        } elseif ($change === 'consent') {
            app(\Modules\FollowUp\Services\ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'withdrawn', 'request', 'ref');
        } elseif ($change === 'policy') {
            FollowUpSetting::query()->firstOrFail()->update(['enabled' => false]);
        } elseif ($change === 'reassignment') {
            $this->opportunity->channel->update(['advisor_bot_id' => Bot::factory()->create()->id]);
        } else {
            $this->bot->update(['tone' => 'Nuevo tono']);
        }

        return new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus');
    });
    $service->generate($action->id, $action->draft_reservation);
    expect($action->fresh()->draft_state)->toBe('invalid')->and($action->fresh()->draft_payload)->toBeNull();
})->with(['reply', 'human', 'advisor', 'consent', 'policy', 'reassignment']);

it('guarda ediciones con versión y rechaza sobrescrituras antiguas', function () {
    $this->client->shouldReceive('chat')->once()->andReturn(new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus'));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    $service->edit($this->admin, $action->id, 1, '¿Qué dudas te gustaría resolver sobre el programa?');
    expect($action->fresh()->draft_version)->toBe(2);
    expect(fn () => $service->edit($this->admin, $action->id, 1, 'Texto anterior'))->toThrow(ValidationException::class);
});

it('maneja fallos y permite reintento explícito', function () {
    $this->client->shouldReceive('chat')->once()->andThrow(new RuntimeException('secret must not be logged'));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    expect($action->fresh()->draft_error)->toBe('generation_failed');
    expect($service->request($this->admin, $action->id, 1)->draft_state)->toBe('queued');
});

it('valida no_contact, idioma, referencias y afirmaciones críticas', function () {
    $validator = app(FollowUpDraftValidator::class);
    expect($validator->validate(draftOutput(['recommended_action' => 'no_contact', 'draft_text' => '']), 'es', [], [])['draft_text'])->toBe('');
    expect($validator->validate(draftOutput(['draft_text' => 'El precio es 500 dólares.']), 'es', [], [])['draft_text'])->toBe('');
    expect(fn () => $validator->validate(draftOutput(['source_references' => ['other_tenant']]), 'es', [], []))->toThrow(ValidationException::class);
    expect(fn () => $validator->validate(draftOutput(['language' => 'en']), 'es', [], []))->toThrow(ValidationException::class);
    expect(fn () => $validator->validate('invalid', 'es', [], []))->toThrow(JsonException::class);
    $verified = draftOutput(['draft_text' => 'El precio es 500 dólares.', 'source_references' => ['PRICE']]);
    expect($validator->validate($verified, 'es', ['PRICE' => 'El precio es 500 dólares.'], [])['draft_text'])->toBe('El precio es 500 dólares.');
    expect($validator->validate(draftOutput(), 'es', [], [['direction' => 'outbound', 'text' => '¿Qué aspecto del programa te gustaría aclarar?']])['warnings'])->toContain('repeated_content');
});

it('aísla las solicitudes y restringe permisos', function () {
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->set($other->id);
    expect(fn () => app(FollowUpDraftService::class)->request($this->admin, $this->action->id, 0))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('recupera reservas vencidas y limita regeneraciones explícitas', function () {
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $action->forceFill(['draft_reserved_at' => now()->subMinutes(6)])->save();
    expect($service->refreshValidity($action))->toBeFalse();
    expect($action->fresh()->draft_error)->toBe('generation_timeout');
    config(['followup.draft_generations_per_hour' => 1]);
    expect(fn () => $service->request($this->admin, $action->id, $action->fresh()->draft_version))->toThrow(ValidationException::class);
});

it('usa únicamente fuentes del asesor y limita el historial', function () {
    $source = \Modules\Ai\Models\KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'AUTHORIZED', 'status' => 'active', 'content_es' => "## Programa\nEl programa dura 10 semanas."]);
    $this->bot->knowledgeSources()->attach($source->id, ['is_active' => true]);
    \Modules\Ai\Models\KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'PRIVATE', 'status' => 'active', 'content_es' => 'Información privada ajena.']);
    SocialMessage::factory()->count(30)->create(['social_conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'body' => str_repeat('historial ', 200)]);
    $context = app(\Modules\FollowUp\Services\FollowUpDraftContext::class)->build($this->action);
    expect($context['history'])->toHaveCount(20)->and($context['sources'])->toHaveKey('AUTHORIZED')->not->toHaveKey('PRIVATE');
    expect(mb_strlen($context['history'][0]['text']))->toBeLessThanOrEqual(1000);
});

it('invalida cambios de fuentes con auditoría idempotente', function () {
    $source = \Modules\Ai\Models\KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'AUTHORIZED', 'status' => 'active', 'content_es' => "## Programa\nDescripción del programa."]);
    $this->bot->knowledgeSources()->attach($source->id, ['is_active' => true]);
    $this->client->shouldReceive('chat')->once()->andReturn(new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus'));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    $source->update(['content_es' => 'Contenido nuevo.']);
    expect($service->refreshValidity($action->fresh()))->toBeFalse();
    $service->refreshValidity($action->fresh());
    expect(\Modules\FollowUp\Models\FollowUpEvent::query()->where('event_type', 'draft.invalidated')->count())->toBe(1);
});

it('la interfaz consulta y encola borradores sin generar al abrir', function () {
    $this->client->shouldNotReceive('chat');
    \Livewire\Livewire::actingAs($this->admin)->test(\Modules\FollowUp\Livewire\Opportunities::class)
        ->call('selectOpportunity', $this->opportunity->id)->assertSee('Borradores de seguimiento')
        ->call('generateDraft', $this->action->id, 0)->assertHasNoErrors();
    expect($this->action->fresh()->draft_state)->toBe('queued');
    Queue::assertPushed(\Modules\FollowUp\Jobs\GenerateFollowUpDraft::class, 1);
});

it('permite editar y descartar desde Livewire y bloquea la edición durante regeneración', function () {
    $this->client->shouldReceive('chat')->once()->andReturn(new AiChatResponse(draftOutput(), 'qwen', 'qwen-plus'));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    $view = \Livewire\Livewire::actingAs($this->admin)->test(\Modules\FollowUp\Livewire\Opportunities::class)
        ->call('selectOpportunity', $this->opportunity->id)->call('openDraft', $action->id)
        ->set('draftText', '¿Qué dudas te gustaría resolver sobre el programa?')->call('saveDraft')->assertHasNoErrors();
    expect($action->fresh()->draft_edited_by)->toBe($this->admin->id);
    $service->request($this->admin, $action->id, 2);
    expect($action->fresh()->draft_edited_by)->toBeNull()->and($action->fresh()->draft_generated_at)->toBeNull();
    expect(fn () => $service->edit($this->admin, $action->id, 2, 'Edición antigua'))->toThrow(ValidationException::class);
    $view->call('discardDraft', $action->id, 3)->assertHasNoErrors();
    expect($action->fresh()->draft_state)->toBe('invalid');
});

it('una recomendación no_contact se conserva y caduca', function () {
    $this->client->shouldReceive('chat')->once()->andReturn(new AiChatResponse(draftOutput(['recommended_action' => 'no_contact', 'draft_text' => '']), 'qwen', 'qwen-plus'));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    expect($action->fresh()->draft_state)->toBe('no_contact');
    $this->travel(25)->hours();
    expect($service->refreshValidity($action->fresh()))->toBeFalse();
});

it('impide generación con consentimiento retirado y usuarios sin permisos', function () {
    app(\Modules\FollowUp\Services\ConsentService::class)->record($this->admin, null, $this->conversation->id, 'whatsapp', 'requested_follow_up', 'withdrawn', 'request', 'ref-1');
    expect(fn () => app(FollowUpDraftService::class)->request($this->admin, $this->action->id, 0))->toThrow(ValidationException::class);
    $user = User::factory()->create(['institution_id' => $this->admin->institution_id, 'role' => 'marketing', 'status' => 'active']);
    expect(fn () => app(FollowUpDraftService::class)->request($user, $this->action->id, 0))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rechaza inyección de comandos de salida y registra timeout normalizado', function () {
    $validator = app(FollowUpDraftValidator::class);
    $payload = $validator->validate(draftOutput(['send' => true, 'consent' => 'granted']), 'es', [], []);
    expect($payload)->not->toHaveKey('send')->not->toHaveKey('consent');
    $this->client->shouldReceive('chat')->once()->andThrow(new \Modules\Ai\Services\AiProviderException(\Modules\Ai\Enums\AiErrorCategory::Timeout));
    $service = app(FollowUpDraftService::class);
    $action = $service->request($this->admin, $this->action->id, 0);
    $service->generate($action->id, $action->draft_reservation);
    expect($action->fresh()->draft_error)->toBe('timeout');
});
