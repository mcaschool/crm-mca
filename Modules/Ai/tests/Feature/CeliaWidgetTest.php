<?php

declare(strict_types=1);

use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Chat\Database\Seeders\ChatTreeSeeder;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Prepara un bot con arbol real, proveedor de IA (config) y una fuente de
 * conocimiento. Devuelve el bot. El cliente de IA se sustituye por un doble en
 * cada prueba: NUNCA se llama a Qwen real.
 */
function celiaBot(): Bot
{
    $institution = Institution::factory()->create();

    $bot = app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create(['public_key' => str_repeat('c', 32), 'assistant_name' => 'Celia']);

        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        AiProcessConfig::factory()->create([
            'bot_id' => $bot->id,
            'process' => 'conversation',
            'integration_id' => $integration->id,
            'model' => 'qwen-plus',
            'status' => 'active',
        ]);

        \Modules\Ai\Models\KnowledgeSource::factory()->create([
            'bot_id' => $bot->id,
            'code' => 'KB-MC-GENERAL-001',
            'priority' => 10,
            'content_es' => "## Certificacion y titulacion\nAl finalizar recibes un diploma y un certificado con verificacion digital, integrables a LinkedIn.\n\n## Metodologia\nCada microcredencial equivale a 6 semanas, online y a ritmo propio.",
            'content_en' => '## Certification\nYou receive a diploma and a certificate.',
            'status' => 'active',
        ]);

        return $bot;
    });

    (new ChatTreeSeeder)->run();

    return $bot;
}

function celiaHeaders(Bot $bot): array
{
    return ['X-Bot-Key' => $bot->public_key];
}

function bindFakeAi(string $content = '{"reply": "Respuesta de prueba", "action": "answer"}'): FakeAiChatClient
{
    $fake = new FakeAiChatClient($content);
    app()->instance(AiChatClient::class, $fake);

    return $fake;
}

/** Inicia sesion y captura un contacto; devuelve el session_id. */
function celiaSession(Bot $bot): string
{
    $session = test()->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');
    test()->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => true,
    ])->assertOk();

    return $session;
}

it('activa el modo Celia y saluda con memoria (nombre) sin gastar IA', function () {
    $bot = celiaBot();
    $fake = bindFakeAi();
    $session = celiaSession($bot);

    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    $res->assertOk()
        ->assertJsonPath('mode', 'celia')
        ->assertJsonPath('used_ai', false);

    expect($res->json('reply'))->toContain('Ana');
    expect($fake->callCount())->toBe(0); // el saludo no llama al proveedor

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () use ($session) {
        $conv = Conversation::query()->where('session_id', $session)->first();
        expect($conv->mode)->toBe('celia');
        expect(Event::query()->where('event_type', 'started_celia')->count())->toBe(1);
    });
});

it('RESPONDE con el conocimiento una pregunta de tema, sin lanzar el menu', function () {
    // Regla de negocio: si el conocimiento tiene la respuesta (duracion, metodos de
    // pago, certificacion...), Celia la RESPONDE con la IA y NO enruta a botones.
    $bot = celiaBot();
    $fake = bindFakeAi('{"reply": "Cada microcredencial equivale a 6 semanas, online y a tu ritmo.", "action": "answer"}');
    $session = celiaSession($bot);
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', [
        'session_id' => $session, 'message' => '¿cuanto dura la microcredencial?',
    ]);

    $res->assertOk()->assertJsonPath('action', 'answer')->assertJsonPath('used_ai', true);
    expect($res->json('node'))->toBeNull(); // no hay menu: es una respuesta
    expect($res->json('reply'))->toContain('6 semanas');
    expect($fake->callCount())->toBe(1); // la IA respondio con el conocimiento
});

it('conversa con IA una pregunta abierta y registra provider/tokens en meta', function () {
    $bot = celiaBot();
    $fake = bindFakeAi('{"reply": "Con gusto te oriento sobre eso.", "action": "answer"}');
    $session = celiaSession($bot);
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', [
        'session_id' => $session, 'message' => '¿Que me recomiendas para crecer como lider de equipo?',
    ]);

    $res->assertOk()->assertJsonPath('used_ai', true)->assertJsonPath('action', 'answer');
    expect($res->json('reply'))->toBe('Con gusto te oriento sobre eso.');
    expect($fake->callCount())->toBe(1);

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        $msg = Message::query()->where('sender_type', 'celia')->where('message_type', 'ai')->latest('id')->first();
        expect($msg)->not->toBeNull();
        expect($msg->meta['provider'])->toBe('qwen');
        expect($msg->meta['model'])->toBe('qwen-plus');
        expect($msg->meta['prompt_tokens'])->toBeGreaterThan(0);
    });
});

it('registra unresolved_question cuando Celia no tiene el dato', function () {
    $bot = celiaBot();
    bindFakeAi('{"reply": "No tengo ese dato con certeza; te dejo el catalogo.", "action": "unresolved"}');
    $session = celiaSession($bot);
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', [
        'session_id' => $session, 'message' => '¿Cual es el precio exacto en euros con impuestos?',
    ])->assertOk()->assertJsonPath('action', 'unresolved');

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Event::query()->where('event_type', 'unresolved_question')->count())->toBe(1);
    });
});

it('respeta el limite de mensajes de IA por sesion', function () {
    config(['crm.celia.message_limit' => 2]);
    $bot = celiaBot();
    $fake = bindFakeAi('{"reply": "ok", "action": "answer"}');
    $session = celiaSession($bot);
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    // Dos mensajes de IA consumen el cupo.
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', ['session_id' => $session, 'message' => 'pregunta abierta uno de muchos'])->assertOk();
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', ['session_id' => $session, 'message' => 'otra pregunta abierta distinta'])->assertOk();

    // El tercero ya no llama a la IA: responde con el mensaje de limite.
    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', ['session_id' => $session, 'message' => 'una tercera pregunta abierta']);
    $res->assertOk()->assertJsonPath('action', 'limit')->assertJsonPath('limit_reached', true);

    expect($fake->callCount())->toBe(2); // solo dos llamadas reales
});

it('saluda en ingles y aclara que los programas son en espanol', function () {
    $bot = celiaBot();
    bindFakeAi();
    $session = celiaSession($bot);

    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start?lang=en', ['session_id' => $session]);

    $res->assertOk();
    expect($res->json('reply'))->toContain('Spanish');
});

it('si no hay proveedor configurado, deriva con honestidad y registra unresolved', function () {
    $bot = celiaBot();
    bindFakeAi();

    // Elimina la config de IA -> el resolver no encuentra proveedor.
    app(CurrentInstitution::class)->runFor($bot->institution_id, fn () => AiProcessConfig::query()->delete());

    $session = celiaSession($bot);
    $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia/start', ['session_id' => $session]);

    $res = $this->withHeaders(celiaHeaders($bot))->postJson('/api/v1/widget/celia', [
        'session_id' => $session, 'message' => 'una consulta abierta cualquiera larga',
    ]);

    $res->assertOk()->assertJsonPath('action', 'unavailable');
    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Event::query()->where('event_type', 'unresolved_question')->count())->toBe(1);
    });
});
