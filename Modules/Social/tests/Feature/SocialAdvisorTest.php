<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Ai\Events\AdvisorTurnHandled;
use Modules\Ai\Models\AdvisorMessageReceipt;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Jobs\RespondWithAdvisor;
use Modules\Social\Livewire\Channels;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialAdvisorResponder;
use Modules\Social\Services\SocialOutboundService;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Instagram, Messenger y WhatsApp con el asesor inteligente (mismo núcleo que la web):
 * mensaje entrante → ingesta → (activado, sin persona, horario, transferencia) →
 * AdvisorTurnService → remitente del canal → mensaje 'bot' en el hilo. Todo APAGADO por defecto.
 * Sin Meta ni IA reales: fixtures reales de webhook, Http::fake y doble de IA.
 *
 * @return array{0: Institution, 1: Bot, 2: FakeAiChatClient}
 */
function sadvCtx(array $channelAttrs = ['advisor_enabled' => true], string $botStatus = 'active'): array
{
    // Interruptor general encendido en las pruebas (en la instalación está APAGADO por defecto).
    config(['social.app_secret' => 'sadv_secret', 'social.secrets.instagram' => 'sadv_secret', 'social.advisor.autoreply_enabled' => true]);
    // Meta devuelve un id distinto por cada envío.
    Http::fake([
        'graph.facebook.com/*/me/messages' => fn () => Http::response(['message_id' => 'm_bot_'.\Illuminate\Support\Str::random(10)]),
        'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.BOT'.\Illuminate\Support\Str::random(10)]]]),
        '*' => Http::response([]),
    ]);

    $inst = Institution::factory()->create();
    [$bot] = app(CurrentInstitution::class)->runFor($inst->id, function () use ($channelAttrs, $botStatus) {
        // Sin espera «está escribiendo…» del asesor: aquí manda la del canal (la del asesor se prueba en SocialTypingTest).
        $bot = Bot::factory()->create(['status' => $botStatus, 'assistant_name' => 'Celia', 'typing_delay' => 0]);
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);

        foreach (['whatsapp' => 'demo_wa_phone', 'messenger' => 'demo_fb_page', 'instagram' => 'demo_ig_user'] as $provider => $ext) {
            SocialChannel::factory()->create($channelAttrs + ['provider' => $provider, 'external_id' => $ext, 'advisor_bot_id' => $bot->id]);
        }

        return [$bot];
    });

    $fake = new FakeAiChatClient('{"reply": "Sí, hay cupo en el próximo grupo.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, $fake];
}

/** Fixture real del proveedor, opcionalmente con otro id de mensaje. */
function sadvFixture(string $provider, ?string $newMid = null): string
{
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/'.$provider.'.json');
    // Mensaje «de ahora» (el asesor no contesta mensajes viejos).
    $raw = str_replace(['1757001000000', '"1757001000"'], [(string) now()->getTimestampMs(), '"'.now()->getTimestamp().'"'], $raw);
    $mid = ['whatsapp' => 'wamid.HBgTEST00000001', 'messenger' => 'm_MSGR00000001', 'instagram' => 'm_IG00000001'][$provider];

    return $newMid === null ? $raw : str_replace($mid, $newMid, $raw);
}

function sadvPost(string $provider, string $raw): TestResponse
{
    return test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'sadv_secret'),
    ], $raw)->assertOk();
}

/** @return \Illuminate\Support\Collection<int, SocialMessage> */
function sadvBotMessages(Institution $inst): \Illuminate\Support\Collection
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('sender_type', 'bot')->get());
}

function sadvConversation(Institution $inst, string $provider): SocialConversation
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialConversation::query()->with('channel')->where('provider', $provider)->firstOrFail());
}

dataset('canales', [
    'instagram' => ['instagram', 'igsid_5552012', 'me/messages'],
    'messenger' => ['messenger', 'psid_8887701', 'me/messages'],
    'whatsapp' => ['whatsapp', '5215559990001', 'demo_wa_phone/messages'],
]);

it('un mensaje entrante recibe la respuesta del asesor por el remitente de su canal', function (string $provider, string $thread, string $endpoint) {
    [$inst, $bot, $fake] = sadvCtx();
    Event::fake([AdvisorTurnHandled::class]);

    sadvPost($provider, sadvFixture($provider));

    expect($fake->calls)->toHaveCount(1);
    $reply = sadvBotMessages($inst)->sole();
    expect($reply->body)->toBe('Sí, hay cupo en el próximo grupo.')
        ->and($reply->direction)->toBe('outbound')
        ->and($reply->status)->toBe('sent')
        ->and($reply->sent_by)->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), $endpoint) && str_contains((string) json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Sí, hay cupo'));

    // Mismo núcleo: entró por AdvisorTurnService con el canal de origen y su conversación externa.
    Event::assertDispatched(AdvisorTurnHandled::class, fn (AdvisorTurnHandled $e) => $e->channel === $provider && ! $e->isTest);
    $memory = app(CurrentInstitution::class)->runFor($inst->id, fn () => Conversation::query()->sole());
    expect($memory->only(['channel', 'external_id', 'is_test', 'bot_id']))
        ->toBe(['channel' => $provider, 'external_id' => $thread, 'is_test' => false, 'bot_id' => $bot->id]);

    // Cero intermediarios: solo se habló con Meta.
    expect(collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST))->unique()->values()->all())->toBe(['graph.facebook.com']);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains(strtolower($r->url()), 'n8n'));
})->with('canales');

it('con el asesor desactivado (por defecto) no se consulta la IA ni se responde', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => false]);

    sadvPost('messenger', sadvFixture('messenger'));

    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0);

    // Un canal nuevo nace con el asesor APAGADO (valor por defecto de la base de datos).
    $fresh = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'otro_ig']));
    expect((bool) $fresh->fresh()->advisor_enabled)->toBeFalse()->and($fresh->fresh()->advisor_bot_id)->toBeNull()
        ->and((int) $fresh->fresh()->advisor_reply_delay)->toBe(0)->and($fresh->fresh()->advisor_schedule)->toBeNull();
});

it('«Tomar conversación» detiene al asesor al instante y «Devolver» lo reactiva', function () {
    [$inst, , $fake] = sadvCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    sadvPost('instagram', sadvFixture('instagram'));
    $conversation = sadvConversation($inst, 'instagram');
    app(CurrentInstitution::class)->set($inst->id);

    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)
        ->assertSee('Asesor inteligente atendiendo')->assertSee('Tomar conversación')
        ->call('takeOver')
        ->assertSee('En atención humana')->assertSee('Devolver al asesor inteligente');
    expect($conversation->fresh()->only(['automation_state', 'assigned_to']))->toBe(['automation_state' => 'human', 'assigned_to' => $agent->id])
        ->and(Conversation::query()->sole()->mode)->toBe('human'); // la memoria del asesor también

    sadvPost('instagram', sadvFixture('instagram', 'm_IG00000002'));
    expect($fake->calls)->toHaveCount(1)->and(sadvBotMessages($inst))->toHaveCount(1); // no respondió

    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)->call('returnToAdvisor')
        ->assertSee('Asesor inteligente atendiendo');
    sadvPost('instagram', sadvFixture('instagram', 'm_IG00000003'));
    expect($fake->calls)->toHaveCount(2)->and(sadvBotMessages($inst))->toHaveCount(2);
});

it('si una persona responde desde la bandeja, el asesor se pausa en esa conversación', function () {
    [$inst, , $fake] = sadvCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    sadvPost('messenger', sadvFixture('messenger'));
    $conversation = sadvConversation($inst, 'messenger');
    app(CurrentInstitution::class)->set($inst->id);

    app(SocialOutboundService::class)->send($conversation, 'Hola, soy Laura del equipo.', $agent);
    expect($conversation->fresh()->automation_state)->toBe('human');

    sadvPost('messenger', sadvFixture('messenger', 'm_MSGR00000002'));
    expect($fake->calls)->toHaveCount(1);
});

it('no responde con el asesor inactivo ni con un asesor de otra institución', function () {
    [$inst, $bot, $fake] = sadvCtx([], 'inactive');
    sadvPost('whatsapp', sadvFixture('whatsapp'));
    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0);

    // Canal que apunta (por manipulación) a un asesor de OTRA institución.
    $other = Institution::factory()->create();
    $foreign = app(CurrentInstitution::class)->runFor($other->id, fn () => Bot::factory()->create(['status' => 'active']));
    app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialChannel::query()->update(['advisor_bot_id' => $foreign->id, 'advisor_enabled' => true]));
    sadvPost('whatsapp', sadvFixture('whatsapp', 'wamid.HBgTEST00000002'));

    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0);
});

it('un mensaje externo duplicado no vuelve a consultar la IA ni duplica mensajes', function () {
    [$inst, , $fake] = sadvCtx();

    sadvPost('whatsapp', sadvFixture('whatsapp'));
    sadvPost('whatsapp', sadvFixture('whatsapp')); // reintento de Meta

    expect($fake->calls)->toHaveCount(1)->and(sadvBotMessages($inst))->toHaveCount(1);
    expect(app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->count()))->toBe(1);
});

it('concurrencia: si otro proceso ya reclamó el mismo mensaje, este no lo procesa (índice único)', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => false]);
    sadvPost('messenger', sadvFixture('messenger')); // ingerido, sin asesor todavía
    $inbound = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->sole());

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        SocialChannel::query()->update(['advisor_enabled' => true]);
        // Otro proceso ya lo reclamó (entrega simultánea del mismo id).
        AdvisorMessageReceipt::query()->create(['channel' => 'messenger', 'external_message_id' => 'm_MSGR00000001', 'status' => 'processing']);
    });

    expect(app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAdvisorResponder::class)->respond($inbound->id)))->toBe('duplicate')
        ->and($fake->calls)->toHaveCount(0)
        ->and(sadvBotMessages($inst))->toHaveCount(0);

    // La base de datos impide un segundo reclamo del mismo mensaje.
    expect(fn () => app(CurrentInstitution::class)->runFor($inst->id, fn () => AdvisorMessageReceipt::query()->create([
        'channel' => 'messenger', 'external_message_id' => 'm_MSGR00000001', 'status' => 'processing',
    ])))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('si la IA falla no se envía ninguna respuesta incorrecta', function () {
    [$inst, , $fake] = sadvCtx();
    $fake->willThrow();

    sadvPost('instagram', sadvFixture('instagram'));

    expect(sadvBotMessages($inst))->toHaveCount(0)
        ->and(sadvConversation($inst, 'instagram')->automation_state)->toBe('waiting_human');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'me/messages') && ! isset($r->data()['sender_action'])); // «escribiendo» no es una respuesta
});

it('un canal sin remitente configurado no consulta la IA ni intenta enviar', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => true, 'credentials' => ['token' => '']]);

    sadvPost('messenger', sadvFixture('messenger'));

    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'messages'));
});

it('si la persona pide hablar con alguien, se transfiere sin IA y queda «Esperando a una persona»', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => true, 'advisor_handoff_message' => 'Te paso con el equipo.']);
    $raw = str_replace('Hola! Vi el diploma en liderazgo en Facebook, ¿cuándo inicia el próximo grupo?', 'Quiero hablar con una persona, por favor', sadvFixture('messenger'));

    sadvPost('messenger', $raw);

    expect($fake->calls)->toHaveCount(0)
        ->and(sadvBotMessages($inst)->sole()->body)->toBe('Te paso con el equipo.')
        ->and(sadvConversation($inst, 'messenger')->automation_state)->toBe('waiting_human');
});

it('si el asesor decide transferir (sus reglas), envía su mensaje y espera a una persona', function () {
    [$inst, $bot, $fake] = sadvCtx();
    $fake->willReturn('{"reply": "Lo veo mejor con una persona del equipo; te escribirá por aquí.", "action": "handoff"}');

    sadvPost('instagram', sadvFixture('instagram'));

    expect(sadvBotMessages($inst)->sole()->body)->toContain('una persona del equipo')
        ->and(sadvConversation($inst, 'instagram')->automation_state)->toBe('waiting_human');
});

it('fuera de horario envía el aviso una sola vez y no consulta la IA', function () {
    [$inst, , $fake] = sadvCtx([
        'advisor_enabled' => true,
        'advisor_schedule' => ['days' => [((int) now()->isoWeekday() % 7) + 1], 'from' => '00:00', 'to' => '23:59'], // otro día
        'advisor_off_hours_message' => 'Te respondemos en horario de atención.',
    ]);

    sadvPost('whatsapp', sadvFixture('whatsapp'));
    sadvPost('whatsapp', sadvFixture('whatsapp', 'wamid.HBgTEST00000002'));

    expect($fake->calls)->toHaveCount(0)
        ->and(sadvBotMessages($inst)->pluck('body')->all())->toBe(['Te respondemos en horario de atención.']);
});

/** Cola PERSISTENTE (tabla jobs), como en producción. */
function sadvPersistentQueue(): void
{
    config(['queue.default' => 'database']);
}

/** Trabajos pendientes en la cola del asesor. */
function sadvPending(): int
{
    return DB::table('jobs')->where('queue', AdvisorDispatcher::queue())->count();
}

it('el webhook confirma al instante: encola la respuesta con la espera del canal y no espera a la IA ni al envío', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => true, 'advisor_reply_delay' => 10]);
    Queue::fake();

    sadvPost('instagram', sadvFixture('instagram'));

    Queue::assertPushedOn(AdvisorDispatcher::queue(), RespondWithAdvisor::class, function (RespondWithAdvisor $job) use ($inst) {
        return $job->institutionId === $inst->id
            && $job->delay instanceof DateTimeInterface
            && abs(now()->diffInSeconds($job->delay) - 10) <= 1;
    });
    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'me/messages') && ! isset($r->data()['sender_action'])); // «escribiendo» no es una respuesta
});

it('con el interruptor general apagado (por defecto) no se encola nada aunque el canal esté activado', function () {
    [, , $fake] = sadvCtx();
    config(['social.advisor.autoreply_enabled' => false]);
    Queue::fake();

    sadvPost('messenger', sadvFixture('messenger'));

    Queue::assertNothingPushed();
    expect($fake->calls)->toHaveCount(0)->and(AdvisorDispatcher::ready())->toBeFalse();
});

it('cola persistente: el worker programado procesa, deja su latido y la respuesta sale una sola vez', function () {
    [$inst, , $fake] = sadvCtx();
    sadvPersistentQueue();

    sadvPost('whatsapp', sadvFixture('whatsapp'));
    expect(sadvPending())->toBe(1)->and($fake->calls)->toHaveCount(0)->and(AdvisorDispatcher::workerRunning())->toBeFalse();

    Artisan::call('social:advisor-worker');

    expect(sadvPending())->toBe(0)
        ->and($fake->calls)->toHaveCount(1)
        ->and(sadvBotMessages($inst))->toHaveCount(1)
        ->and(AdvisorDispatcher::workerRunning())->toBeTrue();

    // Un reintento de Meta del mismo mensaje no encola ni responde otra vez.
    sadvPost('whatsapp', sadvFixture('whatsapp'));
    Artisan::call('social:advisor-worker');
    expect(sadvPending())->toBe(0)->and($fake->calls)->toHaveCount(1)->and(sadvBotMessages($inst))->toHaveCount(1);
});

it('si la IA falla de forma pasajera se reintenta sin duplicar mensajes y responde una sola vez', function () {
    [$inst, , $fake] = sadvCtx();
    sadvPersistentQueue();
    $fake->willThrow();

    sadvPost('instagram', sadvFixture('instagram'));
    Artisan::call('social:advisor-worker');

    // Primer intento fallido: nada enviado, sin decisión registrada, el trabajo vuelve a la cola.
    expect(sadvBotMessages($inst))->toHaveCount(0)
        ->and(sadvPending())->toBe(1)
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => AdvisorMessageReceipt::query()->count()))->toBe(0);

    $fake->recovers();
    $this->travel(31)->seconds();
    Artisan::call('social:advisor-worker');

    expect(sadvPending())->toBe(0)
        ->and($fake->calls)->toHaveCount(2)
        ->and(sadvBotMessages($inst)->pluck('body')->all())->toBe(['Sí, hay cupo en el próximo grupo.'])
        ->and(sadvConversation($inst, 'instagram')->automation_state)->toBe('bot');
    // La memoria del asesor guarda el mensaje del prospecto UNA vez (idempotencia por id del canal).
    $memory = app(CurrentInstitution::class)->runFor($inst->id, fn () => Conversation::query()->sole());
    expect(app(CurrentInstitution::class)->runFor($inst->id, fn () => $memory->messages()->where('sender_type', 'user')->count()))->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('agotados los reintentos no envía nada y deja la conversación «Esperando a una persona»', function () {
    [$inst, , $fake] = sadvCtx();
    sadvPersistentQueue();
    $fake->willThrow();

    sadvPost('messenger', sadvFixture('messenger'));
    Artisan::call('social:advisor-worker');
    $this->travel(31)->seconds();
    Artisan::call('social:advisor-worker');
    $this->travel(121)->seconds();
    Artisan::call('social:advisor-worker');

    expect($fake->calls)->toHaveCount(3)
        ->and(sadvPending())->toBe(0)
        ->and(sadvBotMessages($inst))->toHaveCount(0)
        ->and(sadvConversation($inst, 'messenger')->automation_state)->toBe('waiting_human');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'me/messages') && ! isset($r->data()['sender_action'])); // «escribiendo» no es una respuesta
});

it('no contesta si, durante la espera, respondió una persona del equipo', function () {
    [$inst, , $fake] = sadvCtx(['advisor_enabled' => true, 'advisor_reply_delay' => 10, 'advisor_pause_on_human' => false]);
    sadvPersistentQueue();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    sadvPost('instagram', sadvFixture('instagram'));
    $conversation = sadvConversation($inst, 'instagram');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialOutboundService::class)->send($conversation->fresh(), 'Ya te atiendo yo.', $agent));

    $this->travel(11)->seconds();
    Artisan::call('social:advisor-worker');

    expect($fake->calls)->toHaveCount(0)->and(sadvBotMessages($inst))->toHaveCount(0)->and(sadvPending())->toBe(0);
});

it('al alcanzar el límite de la conversación no responde y la pasa a una persona', function () {
    [$inst, , $fake] = sadvCtx();
    config(['crm.celia.message_limit' => 0]);

    sadvPost('messenger', sadvFixture('messenger'));

    expect($fake->calls)->toHaveCount(0)
        ->and(sadvBotMessages($inst))->toHaveCount(0)
        ->and(sadvConversation($inst, 'messenger')->automation_state)->toBe('waiting_human');
});

it('la configuración del canal es amigable, apagada por defecto y solo admite asesores de la institución', function () {
    [$inst, $bot] = sadvCtx(['advisor_enabled' => false, 'advisor_bot_id' => null]);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($inst->id);
    $channel = SocialChannel::query()->where('provider', 'messenger')->firstOrFail();

    $page = Livewire::actingAs($admin)->test(Channels::class)
        ->assertSee('Asesor inteligente desactivado')
        ->call('editAdvisor', $channel->id)
        ->assertSee('Espera antes de responder')->assertSee('Horario de atención automática')
        ->assertSee('Transferir a una persona')->assertSee('Pausar el asesor en una conversación cuando responda una persona del equipo')
        ->set('advisorAlways', false)->assertSee('Mensaje fuera de horario');

    // El panel del asesor no usa lenguaje técnico.
    $panel = \Illuminate\Support\Str::between($page->html(), 'data-testid="advisor-panel"', '</form>');
    foreach (['webhook', 'endpoint', 'token', 'payload', 'n8n', 'API'] as $word) {
        expect($panel)->not->toContain($word);
    }

    $page->set('advisorEnabled', true)->call('saveAdvisor')->assertHasErrors('advisorBotId');

    $foreign = app(CurrentInstitution::class)->runFor(Institution::factory()->create()->id, fn () => Bot::factory()->create());
    $page->set('advisorBotId', (string) $foreign->id)->call('saveAdvisor')->assertHasErrors('advisorBotId');

    $page->set('advisorBotId', (string) $bot->id)->set('advisorDelay', 5)->set('advisorAlways', false)
        ->set('advisorDays', ['1', '2', '3'])->set('advisorFrom', '08:00')->set('advisorTo', '20:00');

    // Sin el procesamiento automático del servidor funcionando, no se puede activar.
    $page->call('saveAdvisor')->assertHasErrors('advisorEnabled')->assertSee('todavía no está funcionando');
    expect((bool) $channel->fresh()->advisor_enabled)->toBeFalse();

    // Con la cola persistente y el latido reciente del worker, sí.
    sadvPersistentQueue();
    AdvisorDispatcher::touch();
    $page->call('saveAdvisor')->assertHasNoErrors();

    expect($channel->fresh()->only(['advisor_enabled', 'advisor_bot_id', 'advisor_reply_delay', 'advisor_schedule']))->toEqual([
        'advisor_enabled' => true, 'advisor_bot_id' => $bot->id, 'advisor_reply_delay' => 5,
        'advisor_schedule' => ['days' => [1, 2, 3], 'from' => '08:00', 'to' => '20:00'],
    ]);
});
