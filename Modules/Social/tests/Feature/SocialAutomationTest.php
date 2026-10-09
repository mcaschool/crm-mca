<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Notifications\AiServiceAlertNotification;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiChatResponse;
use Modules\Ai\Services\AiExecutionContext;
use Modules\Ai\Services\AiProviderException;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Database\Seeders\SocialDemoSeeder;
use Modules\Social\Jobs\RespondWithAdvisor;
use Modules\Social\Livewire\AdvisorChannels;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialAdvisorResponder;
use Modules\Social\Services\SocialAutomationService;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Atención automática de Instagram, Messenger y WhatsApp con los asesores inteligentes (Sophia o
 * cualquier otro): asignación por cuenta desde la ficha del asesor, control por conversación,
 * errores tipificados con aviso, trazabilidad, agrupación, turno por conversación y anti-bucles.
 * Sin Meta ni IA reales: Http::fake y dobles de IA. Complementa SocialAdvisorTest.
 */

/** Doble de IA que falla con una categoría NORMALIZADA concreta (como la capa de IA real). */
function sautoFailingAi(AiErrorCategory $category): FakeAiChatClient
{
    $fake = new class extends FakeAiChatClient
    {
        public AiErrorCategory $category = AiErrorCategory::Unknown;

        public function chat(Integration $integration, string $model, array $messages, array $params = [], ?AiExecutionContext $context = null): AiChatResponse
        {
            $this->calls[] = compact('integration', 'model', 'messages', 'params', 'context');

            throw new AiProviderException($this->category, message: 'Fallo simulado ('.$this->category->value.').');
        }
    };
    $fake->category = $category;
    app()->instance(AiChatClient::class, $fake);

    return $fake;
}

/**
 * @param  array<string, mixed>  $channelAttrs
 * @return array{0: Institution, 1: Bot, 2: FakeAiChatClient}
 */
function sautoCtx(array $channelAttrs = ['advisor_enabled' => true], ?array $graph = null): array
{
    config(['social.app_secret' => 'sauto_secret', 'social.secrets.instagram' => 'sauto_secret', 'social.advisor.autoreply_enabled' => true]);
    Http::fake($graph ?? [
        'graph.facebook.com/*/me/messages' => fn () => Http::response(['message_id' => 'm_bot_'.uniqid()]),
        'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.BOT'.uniqid()]]]),
        '*' => Http::response([]),
    ]);

    $inst = Institution::factory()->create();
    $bot = app(CurrentInstitution::class)->runFor($inst->id, function () use ($channelAttrs) {
        $bot = Bot::factory()->create(['status' => 'active', 'assistant_name' => 'Sophia', 'typing_delay' => 0]);
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
        foreach (['whatsapp' => 'demo_wa_phone', 'messenger' => 'demo_fb_page', 'instagram' => 'demo_ig_user'] as $provider => $ext) {
            SocialChannel::factory()->create($channelAttrs + ['provider' => $provider, 'external_id' => $ext, 'display_name' => ucfirst($provider).' MCA', 'is_active' => true, 'advisor_bot_id' => $bot->id, 'credentials' => ['token' => 'EAATEST']]);
        }

        return $bot;
    });

    $fake = new FakeAiChatClient('{"reply": "Sí, hay cupo en el próximo grupo.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, $fake];
}

/** Webhook firmado con un payload construido (siempre «de ahora»). */
function sautoPost(string $provider, array $payload): TestResponse
{
    $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'sauto_secret'),
    ], $raw)->assertOk();
}

function sautoMessenger(string $mid, string $text, bool $echo = false): array
{
    $user = ['id' => 'psid_8887701'];
    $page = ['id' => 'demo_fb_page'];

    return ['object' => 'page', 'entry' => [['id' => 'demo_fb_page', 'time' => now()->getTimestampMs(), 'messaging' => [[
        'sender' => $echo ? $page : $user, 'recipient' => $echo ? $user : $page, 'timestamp' => now()->getTimestampMs(),
        'message' => array_filter(['mid' => $mid, 'text' => $text, 'is_echo' => $echo ?: null]),
    ]]]]];
}

/** @param  array<string, mixed>  $message */
function sautoWhatsApp(array $message, string $field = 'messages'): array
{
    $value = ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15551230000', 'phone_number_id' => 'demo_wa_phone']];
    if ($field === 'messages') {
        $value['contacts'] = [['profile' => ['name' => 'Valentina'], 'wa_id' => '5215559990001']];
        $value['messages'] = [$message + ['from' => '5215559990001', 'timestamp' => (string) now()->getTimestamp()]];
    } else {
        $value['message_echoes'] = [$message + ['from' => '15551230000', 'to' => '5215559990001', 'timestamp' => (string) now()->getTimestamp()]];
    }

    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA_1', 'changes' => [['field' => $field, 'value' => $value]]]]];
}

function sautoConversation(Institution $inst, string $provider): SocialConversation
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialConversation::query()->with('channel')->where('provider', $provider)->firstOrFail());
}

/** @return \Illuminate\Support\Collection<int, SocialMessage> */
function sautoBotMessages(Institution $inst): \Illuminate\Support\Collection
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('sender_type', 'bot')->orderBy('id')->get());
}

function sautoReady(): void
{
    config(['queue.default' => 'database']);
    AdvisorDispatcher::touch();
}

function sautoRunWorker(): void
{
    Artisan::call('social:advisor-worker', ['--stop-when-empty' => true]);
}

// ── Asignación desde la ficha del asesor ──────────────────────────────────────

dataset('cuentas', ['instagram', 'messenger', 'whatsapp']);

it('asigna el asesor a una cuenta desde su ficha, con quién y cuándo', function (string $provider) {
    [$inst, $bot] = sautoCtx(['advisor_enabled' => false, 'advisor_bot_id' => null]);
    sautoReady();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $channel = SocialChannel::query()->where('provider', $provider)->firstOrFail();

    Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $bot->id])
        ->assertSee('Canales de atención automática')->assertSee('Web Chat')->assertSee($channel->display_name)
        ->call('toggle', $channel->id)
        ->assertSee('atenderá automáticamente');

    $channel->refresh();
    expect($channel->advisor_enabled)->toBeTrue()
        ->and((int) $channel->advisor_bot_id)->toBe($bot->id)
        ->and((int) $channel->advisor_assigned_by)->toBe($admin->id)
        ->and($channel->advisor_assigned_at)->not->toBeNull();

    // Apagar detiene lo nuevo sin borrar la asignación histórica ni nada de la cuenta.
    Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $bot->id])->call('toggle', $channel->id);
    expect($channel->fresh()->advisor_enabled)->toBeFalse()->and((int) $channel->fresh()->advisor_bot_id)->toBe($bot->id);
})->with('cuentas');

it('todo nace apagado: canal nuevo, conversaciones y el seeder de demostración no activan nada', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $channel = SocialChannel::factory()->create(['provider' => 'messenger']);
    $raw = DB::table('social_channels')->where('id', $channel->id)->first();
    expect((bool) $raw->advisor_enabled)->toBeFalse()
        ->and($raw->advisor_bot_id)->toBeNull()
        ->and($raw->advisor_assigned_by)->toBeNull();

    $this->seed(SocialDemoSeeder::class);
    expect(DB::table('social_channels')->where('advisor_enabled', true)->count())->toBe(0)
        ->and(DB::table('social_conversations')->whereNotNull('automation_reason')->count())->toBe(0);
});

it('conflicto: no toma en silencio una cuenta que atiende otro asesor; la reasignación es explícita y atómica', function () {
    [$inst, $celia] = sautoCtx(['advisor_enabled' => true]);
    sautoReady();
    app(CurrentInstitution::class)->set($inst->id);
    $celia->forceFill(['assistant_name' => 'Celia'])->save();
    $sophia = Bot::factory()->create(['status' => 'active', 'assistant_name' => 'Sophia']);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $channel = SocialChannel::query()->where('provider', 'instagram')->firstOrFail();

    $page = Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $sophia->id])
        ->assertSee('Atendida por Celia')->assertSee('Reasignar a este asesor');
    $page->call('toggle', $channel->id)->assertSee('ya la atiende Celia');
    expect((int) $channel->fresh()->advisor_bot_id)->toBe($celia->id);

    // Reasignación con el asesor anterior que vio el usuario: una sola escritura.
    $page->call('reassign', $channel->id, $celia->id)->assertSee('reasignada a Sophia');
    expect((int) $channel->fresh()->advisor_bot_id)->toBe($sophia->id)->and($channel->fresh()->advisor_enabled)->toBeTrue();

    // Si la cuenta cambió entretanto (dato viejo en pantalla), no se pisa.
    Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $celia->id])
        ->call('reassign', $channel->id, $celia->id)->assertSee('ya la atiende Sophia');
    expect((int) $channel->fresh()->advisor_bot_id)->toBe($sophia->id);
});

it('aislamiento: la ficha solo ve y toca cuentas de su institución', function () {
    [$instA, $botA] = sautoCtx(['advisor_enabled' => false, 'advisor_bot_id' => null]);
    sautoReady();
    $instB = Institution::factory()->create();
    $channelB = app(CurrentInstitution::class)->runFor($instB->id, fn () => SocialChannel::factory()->create(['provider' => 'messenger', 'display_name' => 'Pagina de B']));
    app(CurrentInstitution::class)->set($instA->id);
    $admin = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin']);

    $page = Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $botA->id])->assertDontSee('Pagina de B');
    expect(fn () => $page->call('toggle', $channelB->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(app(CurrentInstitution::class)->runFor($instB->id, fn () => $channelB->fresh()->advisor_enabled))->toBeFalse();

    $marketing = User::factory()->create(['institution_id' => $instA->id, 'role' => 'marketing']);
    Livewire::actingAs($marketing)->test(AdvisorChannels::class, ['botId' => $botA->id])->assertForbidden();   // sin permiso de integraciones
});

// ── Flujo: registrar primero, mismo motor, trazabilidad ───────────────────────

it('registra el entrante y confirma el webhook ANTES de ejecutar la IA', function () {
    [$inst, , $fake] = sautoCtx();
    Queue::fake();

    sautoPost('messenger', sautoMessenger('m_A1', '¿Cuándo empieza el diploma?'));

    expect(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->count()))->toBe(1)
        ->and($fake->calls)->toHaveCount(0);
    Queue::assertPushed(RespondWithAdvisor::class);
});

it('responde con el conocimiento de Sophia y deja la respuesta trazada como IA en la bandeja', function () {
    [$inst, $bot, $fake] = sautoCtx();
    app(CurrentInstitution::class)->runFor($inst->id, function () use ($bot) {
        $source = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'DA-FAQ-1', 'status' => 'active', 'priority' => 10,
            'content_es' => "## Inicio\nEl Diploma Avanzado de Sophia comienza cada primer lunes de mes."]);
        $bot->knowledgeSources()->attach($source->id, ['is_active' => true]);
    });

    sautoPost('messenger', sautoMessenger('m_A2', '¿Cuándo comienza el diploma avanzado?'));

    $prompt = (string) json_encode($fake->calls[0]['messages'] ?? [], JSON_UNESCAPED_UNICODE);
    expect($prompt)->toContain('comienza cada primer lunes de mes');

    $reply = sautoBotMessages($inst)->sole();
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->sole());
    expect($reply->status)->toBe('sent')
        ->and((int) $reply->ai_bot_id)->toBe($bot->id)
        ->and((int) $reply->in_reply_to_id)->toBe($inbound->id)
        ->and($reply->ai_meta)->toMatchArray(['provider' => 'qwen', 'model' => 'qwen-plus', 'input_tokens' => 42, 'output_tokens' => 18, 'used_ai' => true])
        ->and($reply->ai_meta)->toHaveKeys(['duration_ms', 'sent_at'])
        ->and((string) json_encode($reply->ai_meta))->not->toContain('EAATEST');   // nunca credenciales

    // En la bandeja: etiqueta de IA, asesor y «último mensaje enviado por la IA».
    $conversation = sautoConversation($inst, 'messenger');
    app(CurrentInstitution::class)->set($inst->id);
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)
        ->assertSee('Asesor inteligente · IA')->assertSee('Sophia')->assertSee('Último mensaje enviado por la IA')
        ->assertSee('Sí, hay cupo en el próximo grupo.');
});

// ── Duplicados, ecos, reintentos y concurrencia ───────────────────────────────

it('el eco de su propia respuesta (Messenger/Instagram) no entra como mensaje del cliente ni dispara otra respuesta', function () {
    [$inst, , $fake] = sautoCtx(graph: [
        'graph.facebook.com/*/me/messages' => fn (HttpRequest $r) => isset($r->data()['sender_action']) ? Http::response(['success' => true]) : Http::response(['message_id' => 'm_CRMREPLY3']),
        '*' => Http::response([]),
    ]);

    sautoPost('messenger', sautoMessenger('m_A3', 'Hola'));
    sautoPost('messenger', sautoMessenger('m_CRMREPLY3', 'Sí, hay cupo en el próximo grupo.', echo: true));   // mismo mid que devolvió Meta

    expect($fake->calls)->toHaveCount(1)
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->count()))->toBe(1)
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'outbound')->count()))->toBe(1)
        ->and(sautoBotMessages($inst))->toHaveCount(1)
        ->and(sautoConversation($inst, 'messenger')->automation_state)->toBe('bot');
});

it('un reintento del job después de enviar no vuelve a enviar ni a consultar la IA', function () {
    [$inst, , $fake] = sautoCtx();
    sautoPost('instagram', ['object' => 'instagram', 'entry' => [['id' => 'demo_ig_user', 'time' => now()->getTimestampMs(), 'messaging' => [[
        'sender' => ['id' => 'igsid_1'], 'recipient' => ['id' => 'demo_ig_user'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm_IGA4', 'text' => 'Hola'],
    ]]]]]);
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->sole());
    $sent = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'me/messages') && ! isset($p[0]->data()['sender_action']))->count();

    $outcome = app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAdvisorResponder::class)->respond($inbound->id, false));

    expect($outcome)->toBe('duplicate')->and($fake->calls)->toHaveCount(1)
        ->and(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'me/messages') && ! isset($p[0]->data()['sender_action']))->count())->toBe($sent);
});

it('dos workers sobre la misma conversación: el segundo espera su turno sin consultar la IA', function () {
    [$inst, , $fake] = sautoCtx();
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_A5', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->sole());

    // Otro worker tiene el turno (persistente en BD).
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->claim($conversation));
    $busy = app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAdvisorResponder::class)->respond($inbound->id, false));
    expect($busy)->toBe(SocialAdvisorResponder::BUSY)->and($fake->calls)->toHaveCount(0);

    // Libre el turno, responde una vez.
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->release($conversation));
    sautoRunWorker();
    expect($fake->calls)->toHaveCount(1)->and(sautoBotMessages($inst))->toHaveCount(1)
        ->and($conversation->fresh()->advisor_lease_until)->toBeNull();
});

it('varios mensajes seguidos reciben UNA respuesta que los tiene en cuenta todos', function () {
    [$inst, , $fake] = sautoCtx(['advisor_enabled' => true, 'advisor_reply_delay' => 10]);
    sautoReady();

    sautoPost('messenger', sautoMessenger('m_F1', 'Hola'));
    sautoPost('messenger', sautoMessenger('m_F2', 'quiero información'));
    sautoPost('messenger', sautoMessenger('m_F3', 'del Micro MBA'));
    $this->travel(11)->seconds();
    sautoRunWorker();

    expect($fake->calls)->toHaveCount(1)->and(sautoBotMessages($inst))->toHaveCount(1);
    $userTurn = collect($fake->calls[0]['messages'])->where('role', 'user')->last()['content'] ?? '';
    expect($userTurn)->toContain('Hola')->toContain('quiero información')->toContain('del Micro MBA');
});

// ── Persona al mando, pausa y reactivación ────────────────────────────────────

it('si una persona responde desde la app de WhatsApp del teléfono, el asesor se pausa', function () {
    [$inst, , $fake] = sautoCtx();

    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'Hola']]));
    expect($fake->calls)->toHaveCount(1);

    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.APP1', 'type' => 'text', 'text' => ['body' => 'Te atiendo yo, soy Laura.']], 'smb_message_echoes'));
    $conversation = sautoConversation($inst, 'whatsapp');
    expect($conversation->automation_state)->toBe('human')->and($conversation->automation_reason)->toBe('replied_from_app');

    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.IN2', 'type' => 'text', 'text' => ['body' => '¿Y el precio?']]));
    expect($fake->calls)->toHaveCount(1);
});

it('reactivar no contesta mensajes antiguos y queda registrado quién pausó y reactivó', function () {
    [$inst, , $fake] = sautoCtx(['advisor_enabled' => true, 'advisor_reply_delay' => 10]);
    sautoReady();
    app(CurrentInstitution::class)->set($inst->id);
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'name' => 'Laura']);

    sautoPost('messenger', sautoMessenger('m_R1', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)->call('pauseAutomation')
        ->assertSee('Automatización pausada')->assertSee('Pausada por una persona del equipo')->assertSee('Laura');
    expect($conversation->fresh()->only(['automation_state', 'automation_changed_by']))->toBe(['automation_state' => 'paused', 'automation_changed_by' => $agent->id]);

    sautoPost('messenger', sautoMessenger('m_R2', '¿Siguen ahí?'));   // llega mientras está pausada
    $this->travel(2)->seconds();
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)->call('returnToAdvisor');
    expect($conversation->fresh()->only(['automation_state', 'automation_reason', 'automation_changed_by']))
        ->toBe(['automation_state' => 'bot', 'automation_reason' => 'reactivated', 'automation_changed_by' => $agent->id]);

    $this->travel(11)->seconds();
    sautoRunWorker();
    expect($fake->calls)->toHaveCount(0)->and(sautoBotMessages($inst))->toHaveCount(0);   // ni R1 ni R2

    sautoPost('messenger', sautoMessenger('m_R3', 'Quiero inscribirme'));
    $this->travel(11)->seconds();
    sautoRunWorker();
    expect($fake->calls)->toHaveCount(1)->and(sautoBotMessages($inst))->toHaveCount(1);
});

// ── Errores tipificados ───────────────────────────────────────────────────────

it('modelo inexistente: no reintenta, no envía nada, pasa a error y avisa a los administradores', function () {
    [$inst] = sautoCtx();
    $fake = sautoFailingAi(AiErrorCategory::ModelUnavailable);
    Notification::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    sautoReady();

    sautoPost('messenger', sautoMessenger('m_E1', 'Hola'));
    sautoRunWorker();

    $conversation = sautoConversation($inst, 'messenger');
    expect($fake->calls)->toHaveCount(1)
        ->and($conversation->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'ai_model_unavailable'])
        ->and(sautoBotMessages($inst))->toHaveCount(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    Notification::assertSentTo($admin, AiServiceAlertNotification::class, function (AiServiceAlertNotification $n) use ($admin, $conversation) {
        $data = $n->toArray($admin);

        return $data['type'] === 'social_automation_stopped' && $data['category'] === 'ai_model_unavailable'
            && $data['conversation_id'] === $conversation->id
            && ! str_contains((string) json_encode($data, JSON_UNESCAPED_UNICODE), 'Valentina')
            && ! str_contains((string) json_encode($data, JSON_UNESCAPED_UNICODE), 'Hola');
    });
});

it('error del modelo pasajero, tiempo agotado o límite de solicitudes: reintenta y solo al final pasa a error', function (AiErrorCategory $category, string $reason) {
    [$inst] = sautoCtx();
    $fake = sautoFailingAi($category);
    $inbound = null;
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_T1', 'Hola'));
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->sole());
    $respond = fn (bool $final) => app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAdvisorResponder::class)->respond($inbound->id, $final));

    expect($respond(false))->toBe(SocialAdvisorResponder::RETRY)
        ->and(sautoConversation($inst, 'messenger')->automation_state)->toBe('bot');
    expect($respond(true))->toBe('gave_up')
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => $reason])
        ->and($fake->calls)->toHaveCount(2)
        ->and(sautoBotMessages($inst))->toHaveCount(0);
})->with([
    'timeout' => [AiErrorCategory::Timeout, 'ai_timeout'],
    'rate limit' => [AiErrorCategory::RateLimited, 'ai_rate_limited'],
    'proveedor caído' => [AiErrorCategory::ProviderUnavailable, 'ai_error'],
]);

it('credencial de IA inválida no se reintenta', function () {
    [$inst] = sautoCtx();
    sautoFailingAi(AiErrorCategory::AuthenticationError);
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_T2', 'Hola'));
    sautoRunWorker();

    expect(sautoConversation($inst, 'messenger')->automation_reason)->toBe('ai_auth')->and(DB::table('jobs')->count())->toBe(0);
});

it('si el canal rechaza la respuesta ya generada, queda «Fallido» (nunca «Enviado») y la conversación en error', function (array $error, string $reason) {
    [$inst, , $fake] = sautoCtx(graph: [
        'graph.facebook.com/*/me/messages' => fn (HttpRequest $r) => isset($r->data()['sender_action']) ? Http::response(['success' => true]) : Http::response(['error' => $error], 400),
        '*' => Http::response([]),
    ]);
    Notification::fake();

    sautoPost('messenger', sautoMessenger('m_S1', 'Hola'));

    $reply = sautoBotMessages($inst)->sole();
    expect($fake->calls)->toHaveCount(1)
        ->and($reply->status)->not->toBe('sent')
        ->and($reply->ai_meta)->toHaveKey('send_status')
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => $reason]);
})->with([
    'error de envío' => [['code' => 2, 'message' => 'Service temporarily unavailable'], 'send_failed'],
    'token vencido' => [['code' => 190, 'message' => 'Error validating access token'], 'social_token_invalid'],
    'fuera de ventana' => [['code' => 10, 'error_subcode' => 2018278, 'message' => 'outside of allowed window'], 'window_closed'],
]);

it('WhatsApp fuera de la ventana de 24 h: no se envía plantilla automática y queda en error', function () {
    [$inst] = sautoCtx(graph: [
        'graph.facebook.com/*/demo_wa_phone/messages' => fn (HttpRequest $r) => isset($r->data()['typing_indicator']) ? Http::response(['success' => true]) : Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        '*' => Http::response([]),
    ]);

    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.W1', 'type' => 'text', 'text' => ['body' => 'Hola']]));

    expect(sautoBotMessages($inst)->sole()->status)->toBe('failed_window')
        ->and(sautoConversation($inst, 'whatsapp')->automation_reason)->toBe('window_closed');
    Http::assertNotSent(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'template');
});

it('adjunto que el asesor no puede analizar: no inventa, queda registrado y pasa a una persona', function () {
    [$inst, , $fake] = sautoCtx();

    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.IMG1', 'type' => 'image', 'image' => ['id' => 'media_1', 'mime_type' => 'image/jpeg']]));

    $conversation = sautoConversation($inst, 'whatsapp');
    expect($fake->calls)->toHaveCount(0)
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('type', 'image')->count()))->toBe(1)
        ->and($conversation->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'waiting_human', 'automation_reason' => 'unsupported_attachment'])
        ->and(sautoBotMessages($inst)->sole()->body)->toContain('persona del equipo');
});

it('canal o cuenta desconectados: no consulta la IA ni finge un envío', function (array $attrs) {
    [$inst, , $fake] = sautoCtx(['advisor_enabled' => true] + $attrs);

    sautoPost('messenger', sautoMessenger('m_D1', 'Hola'));

    expect($fake->calls)->toHaveCount(0)->and(sautoBotMessages($inst))->toHaveCount(0)
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'channel_disconnected']);
})->with([
    'desconectado' => [['connection_status' => 'disconnected']],
    'sin credencial' => [['credentials' => ['token' => '']]],
]);

it('la bandeja muestra el error con su acción recomendada y permite reactivar', function () {
    [$inst] = sautoCtx(['advisor_enabled' => true, 'connection_status' => 'disconnected']);
    sautoPost('messenger', sautoMessenger('m_U1', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    app(CurrentInstitution::class)->set($inst->id);
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)
        ->assertSee('Error de automatización')->assertSee('El canal está desconectado o sin credencial')
        ->assertSee('Reconecta el canal en Canales.')->assertSee('Reactivar el asesor')
        ->call('returnToAdvisor');
    expect($conversation->fresh()->automation_state)->toBe('bot');
});

// ── Garantías adicionales: turnos, reintentos, ecos, activación, avisos ──────

it('un turno abandonado por la caída de un worker caduca solo y la conversación vuelve a atenderse', function () {
    [$inst] = sautoCtx();
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_L1', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    $claim = fn (): bool => app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->claim($conversation));

    // El worker anterior murió con el turno tomado: nadie lo libera.
    expect($claim())->toBeTrue()->and($claim())->toBeFalse();

    $this->travel(181)->seconds();   // turno máximo: 180 s
    expect($claim())->toBeTrue();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->release($conversation));
    expect($conversation->fresh()->advisor_lease_until)->toBeNull();
});

it('BUSY: esperas crecientes y límite finito; si sigue ocupada en el último intento, error visible sin bucle', function () {
    [$inst, , $fake] = sautoCtx();
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_B1', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    $hold = fn () => app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialConversation::query()->whereKey($conversation->id)->update(['advisor_lease_until' => now()->addSeconds(170)]));

    $this->freezeTime();   // medición exacta de las esperas (sin el segundo que tarda el worker)
    $waits = [];
    foreach ([1, 2, 3, 4] as $attempt) {
        $hold();   // otro worker sigue ocupando la conversación
        sautoRunWorker();
        $job = DB::table('jobs')->first();
        $waits[] = (int) $job->available_at - now()->getTimestamp();
        expect((int) $job->attempts)->toBe($attempt);
        $this->travel(max(1, end($waits)))->seconds();
    }
    expect($waits)->toBe([15, 30, 60, 120]);

    $hold();
    sautoRunWorker();   // 5.º y último intento, aún ocupada
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($fake->calls)->toHaveCount(0)
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'conversation_busy']);
});

it('un reintento tras una caída DESPUÉS de que el proveedor aceptó la respuesta no la reenvía', function () {
    [, , $fake] = sautoCtx();
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_C1', 'Hola'));

    // El proceso muere justo después de que Meta aceptó (al guardar el estado «sent»).
    $crash = true;
    SocialMessage::saving(function (SocialMessage $m) use (&$crash) {
        if ($crash && $m->sender_type === 'bot' && $m->status === 'sent') {
            $crash = false;
            throw new RuntimeException('Caída simulada del worker.');
        }
    });
    $replies = fn (): int => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'me/messages') && ! isset($p[0]->data()['sender_action']))->count();

    sautoRunWorker();
    expect($replies())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);   // el job vuelve a la cola

    $this->travel(31)->seconds();
    sautoRunWorker();
    expect($replies())->toBe(1)                 // NO se reenvía
        ->and($fake->calls)->toHaveCount(1)     // ni se vuelve a consultar la IA
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('fallo de red: antes de conectar es «fallido»; tras conectar (Meta pudo aceptarlo) es «entrega sin confirmar»; nunca se reenvía', function (string $network, string $status, string $reason) {
    [$inst, , $fake] = sautoCtx(graph: [
        'graph.facebook.com/*/me/messages' => function (HttpRequest $r) use ($network) {
            if (isset($r->data()['sender_action'])) {
                return Http::response(['success' => true]);
            }
            throw new Illuminate\Http\Client\ConnectionException($network);
        },
        '*' => Http::response([]),
    ]);
    Notification::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    sautoPost('messenger', sautoMessenger('m_C2', 'Hola'));
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->sole());

    expect(sautoBotMessages($inst)->sole()->status)->toBe($status)
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => $reason]);
    expect(app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAdvisorResponder::class)->respond($inbound->id, false)))->toBe('duplicate');
    expect(sautoBotMessages($inst))->toHaveCount(1)->and($fake->calls)->toHaveCount(1);
    Notification::assertSentToTimes($admin, AiServiceAlertNotification::class, 1);
})->with([
    'conexión rechazada (antes de conectar)' => ['cURL error 7: Failed to connect to graph.facebook.com port 443: Connection refused', 'failed', 'send_failed'],
    'DNS (antes de conectar)' => ['cURL error 6: Could not resolve host: graph.facebook.com', 'failed', 'send_failed'],
    'tiempo agotado tras conectar' => ['cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received', 'delivery_unknown', 'delivery_unknown'],
]);

it('el eco de una respuesta del propio CRM no pausa la IA; una respuesta desde el teléfono sí', function () {
    [$inst] = sautoCtx(graph: [
        'graph.facebook.com/*/demo_wa_phone/messages' => fn (HttpRequest $r) => isset($r->data()['typing_indicator']) ? Http::response(['success' => true]) : Http::response(['messages' => [['id' => 'wamid.CRMBOT1']]]),
        'graph.facebook.com/*/me/messages' => fn (HttpRequest $r) => isset($r->data()['sender_action']) ? Http::response(['success' => true]) : Http::response(['message_id' => 'm_CRMBOT11']),
        '*' => Http::response([]),
    ]);

    // WhatsApp: el CRM responde (wamid.CRMBOT1) y luego llega el eco de ESA misma respuesta.
    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.IN10', 'type' => 'text', 'text' => ['body' => 'Hola']]));
    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.CRMBOT1', 'type' => 'text', 'text' => ['body' => 'Sí, hay cupo en el próximo grupo.']], 'smb_message_echoes'));
    expect(sautoConversation($inst, 'whatsapp')->automation_state)->toBe('bot');

    // Messenger: el eco (is_echo) de la respuesta del CRM tampoco pausa.
    sautoPost('messenger', sautoMessenger('m_IN11', 'Hola'));
    sautoPost('messenger', sautoMessenger('m_CRMBOT11', 'Sí, hay cupo en el próximo grupo.', echo: true));   // mid que devolvió Meta al CRM
    expect(sautoConversation($inst, 'messenger')->automation_state)->toBe('bot');

    // Una respuesta escrita en el teléfono (wamid desconocido) sí pausa.
    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.PHONE1', 'type' => 'text', 'text' => ['body' => 'Te escribo yo.']], 'smb_message_echoes'));
    expect(sautoConversation($inst, 'whatsapp')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'human', 'automation_reason' => 'replied_from_app']);
});

it('la PRIMERA activación de una cuenta solo atiende mensajes posteriores a ese momento', function () {
    [$inst, $bot, $fake] = sautoCtx(['advisor_enabled' => true, 'advisor_reply_delay' => 10]);
    sautoReady();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $channel = SocialChannel::query()->where('provider', 'messenger')->firstOrFail();

    // Un mensaje llega con su job en espera; la cuenta se apaga y se vuelve a activar.
    sautoPost('messenger', sautoMessenger('m_P1', 'Hola, ¿hay cupo?'));
    $this->travel(2)->seconds();
    Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $bot->id])
        ->call('toggle', $channel->id)->call('toggle', $channel->id);
    expect($channel->fresh()->advisor_enabled)->toBeTrue()->and($channel->fresh()->advisor_assigned_at)->not->toBeNull();

    $this->travel(11)->seconds();
    sautoRunWorker();
    expect($fake->calls)->toHaveCount(0);   // anterior a la activación: no se contesta

    sautoPost('messenger', sautoMessenger('m_P2', 'Quiero inscribirme'));
    $this->travel(11)->seconds();
    sautoRunWorker();
    expect($fake->calls)->toHaveCount(1)->and(sautoBotMessages($inst))->toHaveCount(1);
});

it('los avisos críticos equivalentes se deduplican (un incidente = una alerta en la ventana)', function () {
    [$inst] = sautoCtx(['advisor_enabled' => true, 'connection_status' => 'disconnected']);
    Notification::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    sautoPost('messenger', sautoMessenger('m_N1', 'Hola'));
    $conversation = sautoConversation($inst, 'messenger');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor($conversation));
    $this->travel(2)->seconds();
    sautoPost('messenger', sautoMessenger('m_N2', '¿Hola?'));

    expect(sautoConversation($inst, 'messenger')->automation_state)->toBe('error');
    Notification::assertSentToTimes($admin, AiServiceAlertNotification::class, 1);
});

it('un estado de error no reintenta solo: los mensajes nuevos no consultan la IA ni dejan trabajos pendientes', function () {
    [$inst] = sautoCtx();
    $fake = sautoFailingAi(AiErrorCategory::ModelUnavailable);
    sautoReady();
    sautoPost('messenger', sautoMessenger('m_X1', 'Hola'));
    sautoRunWorker();
    expect(sautoConversation($inst, 'messenger')->automation_state)->toBe('error')->and($fake->calls)->toHaveCount(1);

    foreach (['m_X2', 'm_X3'] as $mid) {
        sautoPost('messenger', sautoMessenger($mid, 'Hola de nuevo'));
        $this->travel(5)->seconds();
        sautoRunWorker();
    }
    expect($fake->calls)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(sautoConversation($inst, 'messenger')->automation_state)->toBe('error');
});

// ── Intervención humana externa: ecos de Messenger, Instagram y Business Suite ──

function sautoInstagram(string $mid, string $text, bool $echo = false, string $account = 'demo_ig_user', string $user = 'igsid_5552012'): array
{
    return ['object' => 'instagram', 'entry' => [['id' => $account, 'time' => now()->getTimestampMs(), 'messaging' => [[
        'sender' => ['id' => $echo ? $account : $user], 'recipient' => ['id' => $echo ? $user : $account], 'timestamp' => now()->getTimestampMs(),
        'message' => array_filter(['mid' => $mid, 'text' => $text, 'is_echo' => $echo ?: null]),
    ]]]]];
}

it('eco desconocido de Messenger (Business Suite o la app de Messenger): pausa al instante con motivo visible', function () {
    [$inst, , $fake] = sautoCtx();
    sautoPost('messenger', sautoMessenger('m_H1', 'Hola'));
    expect($fake->calls)->toHaveCount(1);

    sautoPost('messenger', sautoMessenger('m_SUITE1', 'Hola, soy Laura; te atiendo yo.', echo: true));

    $conversation = sautoConversation($inst, 'messenger');
    expect($conversation->only(['automation_state', 'automation_reason', 'automation_changed_by']))
        ->toBe(['automation_state' => 'human', 'automation_reason' => 'replied_externally', 'automation_changed_by' => null])
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('sender_type', 'app')->sole()->body))->toBe('Hola, soy Laura; te atiendo yo.');

    sautoPost('messenger', sautoMessenger('m_H2', '¿Y el precio?'));
    expect($fake->calls)->toHaveCount(1);   // ya no responde en paralelo con la persona
});

it('eco desconocido de Instagram: pausa al instante', function () {
    [$inst, , $fake] = sautoCtx();
    sautoPost('instagram', sautoInstagram('m_IGH1', 'Hola'));
    sautoPost('instagram', sautoInstagram('m_IGSUITE1', 'Te respondo desde Instagram.', echo: true));

    expect(sautoConversation($inst, 'instagram')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'human', 'automation_reason' => 'replied_externally']);
    sautoPost('instagram', sautoInstagram('m_IGH2', 'Gracias'));
    expect($fake->calls)->toHaveCount(1);
});

it('un eco repetido no crea mensajes, auditorías ni pausas duplicadas', function () {
    [$inst] = sautoCtx();
    sautoPost('messenger', sautoMessenger('m_D10', 'Hola'));
    sautoPost('messenger', sautoMessenger('m_SUITE2', 'Te atiendo yo.', echo: true));
    $first = sautoConversation($inst, 'messenger')->automation_changed_at;

    $this->travel(30)->seconds();
    sautoPost('messenger', sautoMessenger('m_SUITE2', 'Te atiendo yo.', echo: true));     // reintento de Meta
    sautoPost('messenger', sautoMessenger('m_SUITE3', 'Otra respuesta mía.', echo: true));  // otra respuesta ya en atención humana

    $conversation = sautoConversation($inst, 'messenger');
    expect($conversation->automation_changed_at->equalTo($first))->toBeTrue()
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('external_message_id', 'm_SUITE2')->count()))->toBe(1);
});

it('aislamiento: un eco solo pausa la conversación de SU cuenta e institución', function () {
    [$instA, , $fake] = sautoCtx();
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($instB->id, fn () => SocialChannel::factory()->create([
        'provider' => 'messenger', 'external_id' => 'page_de_b', 'is_active' => true, 'advisor_enabled' => false, 'credentials' => ['token' => 'EAAB'],
    ]));

    // Misma persona escribe a la Página de A (Messenger) y a la cuenta de Instagram de A.
    sautoPost('messenger', sautoMessenger('m_I1', 'Hola'));
    sautoPost('instagram', sautoInstagram('m_I2', 'Hola', user: 'psid_8887701'));

    // Responde una persona desde la Página de B: no afecta a nada de A.
    $echoB = sautoMessenger('m_I3', 'Respuesta de B', echo: true);
    $echoB['entry'][0]['id'] = 'page_de_b';
    $echoB['entry'][0]['messaging'][0]['sender'] = ['id' => 'page_de_b'];
    sautoPost('messenger', $echoB);
    // Responde una persona en Instagram de A: no pausa la conversación de Messenger de A.
    sautoPost('instagram', sautoInstagram('m_I4', 'Te escribo desde Instagram', echo: true, user: 'psid_8887701'));

    expect(sautoConversation($instA, 'messenger')->automation_state)->toBe('bot')
        ->and(sautoConversation($instA, 'instagram')->automation_state)->toBe('human')
        ->and(app(CurrentInstitution::class)->runFor($instB->id, fn () => SocialMessage::query()->where('external_message_id', 'm_I3')->count()))->toBe(1)
        ->and(app(CurrentInstitution::class)->runFor($instA->id, fn () => SocialMessage::query()->where('external_message_id', 'm_I3')->count()))->toBe(0);
});

// ── Recuperación de envíos sin confirmar («Enviando…» nunca es indefinido) ─────

/** Caída simulada del proceso al guardar un saliente del asesor con ese estado (una vez). */
function sautoCrashOn(string $status): void
{
    $armed = true;
    SocialMessage::saving(function (SocialMessage $m) use (&$armed, $status) {
        if ($armed && $m->sender_type === 'bot' && $m->status === $status) {
            $armed = false;
            throw new RuntimeException('Caída simulada del worker.');
        }
    });
}

function sautoReplies(): int
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'me/messages') && ! isset($p[0]->data()['sender_action']))->count();
}

function sautoReconcile(): void
{
    Artisan::call('social:reconcile-deliveries');
}

it('caída ANTES de llamar al proveedor: nunca se envió → «fallido» por plazo, sin reenvío', function () {
    [$inst, , $fake] = sautoCtx();
    sautoReady();
    sautoCrashOn('sending');   // muere al marcar «enviándose»: Meta no se llama
    sautoPost('messenger', sautoMessenger('m_K1', 'Hola'));
    sautoRunWorker();

    $reply = sautoBotMessages($inst)->sole();
    expect($reply->status)->toBe('pending')->and(sautoReplies())->toBe(0);

    sautoReconcile();   // aún dentro del plazo: no se toca
    expect($reply->fresh()->status)->toBe('pending');

    $this->travel(181)->seconds();
    sautoReconcile();
    sautoRunWorker();   // el reintento del job no reenvía
    expect($reply->fresh()->status)->toBe('failed')
        ->and($reply->fresh()->ai_meta['send_status'] ?? null)->toBe('not_sent')
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'send_failed'])
        ->and(sautoReplies())->toBe(0)
        ->and($fake->calls)->toHaveCount(1);
});

it('el proveedor acepta y cae el worker: sin confirmación termina en «entrega sin confirmar», pausa, una alerta y nunca reenvía', function () {
    [$inst, , $fake] = sautoCtx();
    sautoReady();
    Notification::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    sautoCrashOn('sent');   // Meta ya aceptó cuando el proceso muere
    sautoPost('messenger', sautoMessenger('m_K2', 'Hola'));
    sautoRunWorker();

    $reply = sautoBotMessages($inst)->sole();
    expect($reply->status)->toBe('sending')->and(sautoReplies())->toBe(1);

    $this->travel(181)->seconds();
    sautoReconcile();
    sautoReconcile();   // repetido: idempotente
    foreach ([31, 121, 121] as $wait) {   // reintentos del job: ninguno reenvía
        $this->travel($wait)->seconds();
        sautoRunWorker();
    }
    sautoPost('messenger', sautoMessenger('m_K3', '¿Sigues ahí?'));   // en error: no consulta la IA

    expect($reply->fresh()->status)->toBe('delivery_unknown')
        ->and(sautoConversation($inst, 'messenger')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'delivery_unknown'])
        ->and(sautoReplies())->toBe(1)
        ->and($fake->calls)->toHaveCount(1);
    Notification::assertSentToTimes($admin, AiServiceAlertNotification::class, 1);

    // La bandeja lo muestra y pide revisión humana.
    app(CurrentInstitution::class)->set($inst->id);
    Livewire::actingAs($admin)->test(Inbox::class)->call('select', $reply->social_conversation_id)
        ->assertSee('Entrega sin confirmar: revísala en el canal')
        ->assertSee('No se pudo confirmar si la respuesta automática llegó')
        ->assertSee('no se reenvió para no duplicarla');
});

it('un eco posterior confirma el envío que quedó sin confirmar (sin crear otro mensaje ni pausar)', function () {
    [$inst] = sautoCtx(graph: [
        'graph.facebook.com/*/me/messages' => fn (HttpRequest $r) => isset($r->data()['sender_action']) ? Http::response(['success' => true]) : Http::response(['message_id' => 'm_CRMLOST1']),
        '*' => Http::response([]),
    ]);
    sautoReady();
    sautoCrashOn('sent');
    sautoPost('messenger', sautoMessenger('m_K4', 'Hola'));
    sautoRunWorker();
    $reply = sautoBotMessages($inst)->sole();
    expect($reply->status)->toBe('sending')->and($reply->external_message_id)->toBeNull();

    sautoPost('messenger', sautoMessenger('m_CRMLOST1', 'Sí, hay cupo en el próximo grupo.', echo: true));

    expect($reply->fresh()->only(['status', 'external_message_id']))->toBe(['status' => 'sent', 'external_message_id' => 'm_CRMLOST1'])
        ->and($reply->fresh()->ai_meta['reconciled_by'] ?? null)->toBe('echo')
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'outbound')->count()))->toBe(1)
        ->and(sautoConversation($inst, 'messenger')->automation_state)->toBe('bot');

    $this->travel(181)->seconds();
    sautoReconcile();
    expect($reply->fresh()->status)->toBe('sent');
});

it('confirmación posterior de RECHAZO del proveedor: la respuesta pasa a fallida y la automatización se detiene', function () {
    [$inst] = sautoCtx(graph: [
        'graph.facebook.com/*/demo_wa_phone/messages' => fn (HttpRequest $r) => isset($r->data()['typing_indicator']) ? Http::response(['success' => true]) : Http::response(['messages' => [['id' => 'wamid.BOTREJ1']]]),
        '*' => Http::response([]),
    ]);
    sautoPost('whatsapp', sautoWhatsApp(['id' => 'wamid.R1', 'type' => 'text', 'text' => ['body' => 'Hola']]));
    expect(sautoBotMessages($inst)->sole()->status)->toBe('sent');

    sautoPost('whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA_1', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15551230000', 'phone_number_id' => 'demo_wa_phone'],
        'statuses' => [['id' => 'wamid.BOTREJ1', 'status' => 'failed', 'timestamp' => (string) now()->getTimestamp(), 'recipient_id' => '5215559990001', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]],
    ]]]]]]);

    expect(sautoBotMessages($inst)->sole()->status)->toBe('failed')
        ->and(sautoConversation($inst, 'whatsapp')->only(['automation_state', 'automation_reason']))->toBe(['automation_state' => 'error', 'automation_reason' => 'send_failed']);
});

it('varios envíos sin confirmar de la misma cuenta: cada conversación se pausa y se genera UNA sola alerta', function () {
    [$inst] = sautoCtx();
    Notification::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $channel = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialChannel::query()->where('provider', 'messenger')->firstOrFail());
    $ids = app(CurrentInstitution::class)->runFor($inst->id, function () use ($channel) {
        return collect(['psid_a', 'psid_b'])->map(function (string $psid) use ($channel) {
            $conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'messenger', 'external_conversation_id' => $psid, 'automation_state' => 'bot']);

            return SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'outbound', 'sender_type' => 'bot', 'status' => 'sending', 'external_message_id' => null, 'body' => 'Respuesta'])->id;
        });
    });

    $this->travel(181)->seconds();
    sautoReconcile();

    app(CurrentInstitution::class)->runFor($inst->id, function () use ($ids) {
        foreach ($ids as $id) {
            $m = SocialMessage::query()->findOrFail($id);
            expect($m->status)->toBe('delivery_unknown')
                ->and(SocialConversation::query()->findOrFail($m->social_conversation_id)->automation_state)->toBe('error');
        }
    });
    Notification::assertSentToTimes($admin, AiServiceAlertNotification::class, 1);
});

it('ningún saliente queda «Enviando…» indefinidamente (asesor o persona, en cualquier institución); los recientes no se tocan', function () {
    [$instA] = sautoCtx();
    $instB = Institution::factory()->create();
    $make = function (Institution $inst, string $sender, string $status) {
        return app(CurrentInstitution::class)->runFor($inst->id, function () use ($sender, $status) {
            $channel = SocialChannel::query()->first() ?? SocialChannel::factory()->create(['provider' => 'messenger']);
            $conversation = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => $channel->provider]);

            return SocialMessage::factory()->create(['social_conversation_id' => $conversation->id, 'direction' => 'outbound', 'sender_type' => $sender, 'status' => $status, 'external_message_id' => null, 'body' => 'x'])->id;
        });
    };
    $old = [$make($instA, 'bot', 'sending'), $make($instA, 'agent', 'pending'), $make($instB, 'agent', 'sending')];
    $this->travel(181)->seconds();
    $fresh = $make($instA, 'agent', 'sending');   // en curso: dentro del plazo

    sautoReconcile();
    $statuses = fn (array $ids) => DB::table('social_messages')->whereIn('id', $ids)->orderBy('id')->pluck('status')->all();
    expect($statuses($old))->toBe(['delivery_unknown', 'failed', 'delivery_unknown'])
        ->and($statuses([$fresh]))->toBe(['sending']);

    $this->travel(181)->seconds();
    sautoReconcile();
    expect(DB::table('social_messages')->whereIn('status', ['pending', 'sending'])->count())->toBe(0);
});

// ── Worker programado: escucha la cola durante toda su ventana ────────────────

it('el worker sigue escuchando: un trabajo con 2 s de retraso se responde en la MISMA ejecución', function () {
    [$inst, $bot, $fake] = sautoCtx();
    sautoReady();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => $bot->forceFill(['typing_delay' => 2])->save());

    sautoPost('messenger', sautoMessenger('m_W1', 'Hola'));
    $job = DB::table('jobs')->sole();
    expect((int) $job->available_at)->toBeGreaterThan(now()->getTimestamp());   // retrasado: aún no disponible

    // Comportamiento anterior (--stop-when-empty): la cola «está vacía» al empezar y termina sin responder.
    Artisan::call('social:advisor-worker', ['--stop-when-empty' => true]);
    expect($fake->calls)->toHaveCount(0)->and(DB::table('jobs')->count())->toBe(1);

    // Producción: sigue escuchando (sleep 1 s) y lo procesa en cuanto vence, en la misma ejecución.
    $started = microtime(true);
    Artisan::call('social:advisor-worker', ['--max-time' => 6]);
    $elapsed = microtime(true) - $started;

    expect($fake->calls)->toHaveCount(1)
        ->and(sautoBotMessages($inst)->sole()->status)->toBe('sent')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($elapsed)->toBeGreaterThan(1.0)->toBeLessThan(15.0);   // terminó por --max-time, no colgado
});
