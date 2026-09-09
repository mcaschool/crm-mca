<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * Webhook directo Meta ↔ CRM (Bloque 3). Se prueba SIN Meta real, con payloads-fixture
 * reales por proveedor. Cubre: handshake, firma HMAC, normalización por proveedor,
 * idempotencia, canal desconocido (park), eventos no soportados, y que institution_id
 * SIEMPRE sale del canal (nunca del payload).
 */
const WH_SECRET = 'test_app_secret';

const WH_VERIFY = 'test_verify_token';

beforeEach(function () {
    // Instagram (IG Login) usa su PROPIO secret y no cae al común de Facebook; en las pruebas
    // le damos el mismo valor para que la firma por defecto valide igual que WhatsApp/Messenger.
    config([
        'social.app_secret' => WH_SECRET,
        'social.secrets.instagram' => WH_SECRET,
        'social.webhook_verify_token' => WH_VERIFY,
    ]);
    // La ingesta de Messenger/IG intenta resolver nombre+foto vía Graph; sin credenciales
    // reales aquí, se stubea todo a 200 vacío para no hacer llamadas de red (no altera el
    // nombre del contacto: la resolución se prueba aparte en SocialContactProfileTest).
    Http::fake();
});

/** Institución con los 3 canales cuyos external_id coinciden con los fixtures. */
function socialWebhookCtx(): Institution
{
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        SocialChannel::factory()->create(['provider' => 'whatsapp', 'external_id' => 'demo_wa_phone']);
        SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'demo_fb_page']);
        SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'demo_ig_user']);
    });

    return $institution;
}

function fx(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/'.$name.'.json');
}

function postWebhook(string $provider, string $raw, ?string $signSecret = WH_SECRET): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signSecret !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $signSecret);
    }

    return test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], $server, $raw);
}

// ----------------------------------------------------------------------------------
// Ingesta por proveedor (fixtures reales)
// ----------------------------------------------------------------------------------
dataset('proveedores', [
    'whatsapp' => ['whatsapp', 'demo_wa_phone', 'wamid.HBgTEST00000001', '5215559990001'],
    'messenger' => ['messenger', 'demo_fb_page', 'm_MSGR00000001', 'psid_8887701'],
    'instagram' => ['instagram', 'demo_ig_user', 'm_IG00000001', 'igsid_5552012'],
]);

it('ingiere un mensaje entrante y crea conversación + mensaje', function (string $provider, string $channelExt, string $mid, string $convExt) {
    $institution = socialWebhookCtx();

    $res = postWebhook($provider, fx($provider));

    $res->assertOk()->assertJsonPath('results.0.status', 'created');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($provider, $mid, $convExt) {
        $conv = SocialConversation::query()->where('external_conversation_id', $convExt)->first();
        expect($conv)->not->toBeNull();
        expect($conv->provider)->toBe($provider);
        expect($conv->unread_count)->toBe(1);
        expect($conv->last_message_preview)->not->toBeNull();

        $msg = SocialMessage::query()->where('external_message_id', $mid)->first();
        expect($msg)->not->toBeNull();
        expect($msg->direction)->toBe('inbound');
        expect($msg->sender_type)->toBe('contact');
        expect($msg->social_conversation_id)->toBe($conv->id);
    });
})->with('proveedores');

// ----------------------------------------------------------------------------------
// Handshake (GET)
// ----------------------------------------------------------------------------------
it('el handshake devuelve el challenge con verify_token correcto', function () {
    $res = test()->get('/api/social/webhook/whatsapp?hub.mode=subscribe&hub.verify_token='.WH_VERIFY.'&hub.challenge=CHALLENGE_42');

    $res->assertOk();
    expect($res->getContent())->toBe('CHALLENGE_42');
});

it('el handshake responde 403 con verify_token incorrecto', function () {
    test()->get('/api/social/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=X')
        ->assertForbidden();
});

// ----------------------------------------------------------------------------------
// Firma (POST)
// ----------------------------------------------------------------------------------
it('rechaza (401) un POST sin firma', function () {
    socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'), signSecret: null)->assertUnauthorized();
});

it('rechaza (401) un POST con firma inválida', function () {
    socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'), signSecret: 'otro_secreto')->assertUnauthorized();
});

it('no crea nada cuando la firma es inválida', function () {
    $institution = socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'), signSecret: 'malo')->assertUnauthorized();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialMessage::query()->count())->toBe(0);
    });
});

// ----------------------------------------------------------------------------------
// Idempotencia
// ----------------------------------------------------------------------------------
it('es idempotente: reenviar el mismo mid no duplica (status=duplicate)', function () {
    $institution = socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'))->assertOk()->assertJsonPath('results.0.status', 'created');
    postWebhook('whatsapp', fx('whatsapp'))->assertOk()->assertJsonPath('results.0.status', 'duplicate');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialMessage::query()->count())->toBe(1);
        // El reintento no vuelve a subir el contador de no leídos.
        expect(SocialConversation::query()->first()->unread_count)->toBe(1);
    });
});

// ----------------------------------------------------------------------------------
// Canal desconocido → 200 + park (sin crear nada, sin 4xx que gatille reintentos)
// ----------------------------------------------------------------------------------
it('canal desconocido: responde 200 (park) y no crea nada', function () {
    $institution = socialWebhookCtx();
    $raw = str_replace('demo_wa_phone', 'canal_inexistente', fx('whatsapp'));

    postWebhook('whatsapp', $raw)->assertOk()->assertJsonPath('results.0.status', 'parked');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialMessage::query()->count())->toBe(0);
    });
});

// ----------------------------------------------------------------------------------
// Regresión Messenger (post-incidente): el canal se resuelve por provider + external_id.
// En producción el canal 'messenger' tenía un external_id equivocado en social_channels y
// los entrantes se aparcaban; el arreglo fue el DATO de BD, no el código. Se fija ambos
// extremos: external_id que no casa → parked; external_id correcto → mensaje ingerido.
// ----------------------------------------------------------------------------------
it('Messenger inbound: external_id que no coincide aparca; el correcto ingiere', function () {
    $institution = socialWebhookCtx();   // crea el canal 'messenger' con external_id demo_fb_page

    // Page ID entrante que NO coincide con el external_id del canal → parked, nada creado.
    $mismatch = str_replace('demo_fb_page', 'page_id_no_registrado', fx('messenger'));
    postWebhook('messenger', $mismatch)->assertOk()->assertJsonPath('results.0.status', 'parked');
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(SocialMessage::query()->count())->toBe(0));

    // Mismo payload con el external_id correcto → created (canal resuelto por provider+external_id).
    postWebhook('messenger', fx('messenger'))->assertOk()->assertJsonPath('results.0.status', 'created');
    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $conv = SocialConversation::query()->where('provider', 'messenger')->first();
        expect($conv)->not->toBeNull();
        expect(SocialMessage::query()->where('external_message_id', 'm_MSGR00000001')->count())->toBe(1);
    });
});

// ----------------------------------------------------------------------------------
// Eventos no soportados → 200 + ignored, sin crear mensaje
// ----------------------------------------------------------------------------------
it('ignora un echo (is_echo) sin crear mensaje', function () {
    $institution = socialWebhookCtx();
    $payload = json_decode(fx('messenger'), true);
    $payload['entry'][0]['messaging'][0]['message']['is_echo'] = true;
    $raw = (string) json_encode($payload);

    postWebhook('messenger', $raw)->assertOk()->assertJsonPath('status', 'ignored');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialMessage::query()->count())->toBe(0);
    });
});

it('ignora un evento de feed/comentarios (no messaging) sin crear mensaje', function () {
    $institution = socialWebhookCtx();
    $raw = (string) json_encode([
        'object' => 'page',
        'entry' => [['id' => 'demo_fb_page', 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment']]]]],
    ]);

    postWebhook('messenger', $raw)->assertOk()->assertJsonPath('status', 'ignored');

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(SocialMessage::query()->count())->toBe(0));
});

// ----------------------------------------------------------------------------------
// Seguridad: institution_id SIEMPRE del canal, jamás del payload
// ----------------------------------------------------------------------------------
it('ignora cualquier institución que venga en el payload; usa la del canal', function () {
    $institution = socialWebhookCtx();
    $otra = Institution::factory()->create();

    $payload = json_decode(fx('whatsapp'), true);
    $payload['institution_id'] = $otra->id;                 // intento de forzar otra institución
    $payload['entry'][0]['institution_id'] = $otra->id;
    $raw = (string) json_encode($payload);

    postWebhook('whatsapp', $raw)->assertOk()->assertJsonPath('results.0.status', 'created');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution) {
        $conv = SocialConversation::query()->first();
        expect($conv)->not->toBeNull();
        expect($conv->institution_id)->toBe($institution->id);
        expect(SocialMessage::query()->first()->institution_id)->toBe($institution->id);
    });

    // La otra institución no ve nada.
    app(CurrentInstitution::class)->runFor($otra->id, fn () => expect(SocialConversation::query()->count())->toBe(0));
});

// ----------------------------------------------------------------------------------
// Bloque 3.1 · Firma POR PROVEEDOR (Instagram Login = app aparte, secreto propio)
// ----------------------------------------------------------------------------------
it('valida el webhook de Instagram con el secreto de IG (por proveedor)', function () {
    socialWebhookCtx();
    config(['social.secrets.instagram' => 'ig_secret', 'social.secrets.whatsapp' => 'wa_secret']);

    postWebhook('instagram', fx('instagram'), signSecret: 'ig_secret')
        ->assertOk()->assertJsonPath('results.0.status', 'created');
});

it('rechaza (401) el webhook de Instagram firmado con el secreto de OTRA app', function () {
    socialWebhookCtx();
    config(['social.secrets.instagram' => 'ig_secret', 'social.secrets.whatsapp' => 'wa_secret']);

    // El secreto de WhatsApp/Facebook no debe validar un webhook de IG.
    postWebhook('instagram', fx('instagram'), signSecret: 'wa_secret')->assertUnauthorized();
});

it('cada proveedor cae al secreto común cuando no tiene uno propio', function () {
    socialWebhookCtx();
    config(['social.secrets.whatsapp' => null, 'social.app_secret' => 'comun']);

    postWebhook('whatsapp', fx('whatsapp'), signSecret: 'comun')
        ->assertOk()->assertJsonPath('results.0.status', 'created');
});

// ----------------------------------------------------------------------------------
// Bloque 3.1 · Coexistencia WhatsApp: el eco entrante entra como SALIENTE (sender 'app')
// ----------------------------------------------------------------------------------
it('ingiere el eco de coexistencia de WhatsApp como saliente (sender_type=app) y NO sube unread', function () {
    $institution = socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'))->assertOk();                 // entrante → unread=1
    postWebhook('whatsapp', fx('whatsapp_echo'))->assertOk()->assertJsonPath('results.0.status', 'created');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $conv = SocialConversation::query()->first();
        expect($conv->unread_count)->toBe(1);                            // el saliente no sube unread
        expect($conv->last_message_preview)->toContain('aún hay cupo');  // sí actualiza preview
        expect($conv->last_message_at->timestamp)->toBe(1757002000);     // y last_message_at

        $echo = SocialMessage::query()->where('external_message_id', 'wamid.ECHO00000001')->first();
        expect($echo)->not->toBeNull();
        expect($echo->direction)->toBe('outbound');
        expect($echo->sender_type)->toBe('app');
        expect($echo->social_conversation_id)->toBe($conv->id);
    });
});

it('el eco de coexistencia es idempotente por message id', function () {
    $institution = socialWebhookCtx();
    postWebhook('whatsapp', fx('whatsapp'));

    postWebhook('whatsapp', fx('whatsapp_echo'))->assertJsonPath('results.0.status', 'created');
    postWebhook('whatsapp', fx('whatsapp_echo'))->assertJsonPath('results.0.status', 'duplicate');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialMessage::query()->where('sender_type', 'app')->count())->toBe(1);
    });
});

// ----------------------------------------------------------------------------------
// 2º inbound en conversación existente: sube unread, actualiza preview/last_message_at
// ----------------------------------------------------------------------------------
it('un 2º inbound sube unread_count, actualiza preview y avanza last_message_at', function () {
    $institution = socialWebhookCtx();

    postWebhook('whatsapp', fx('whatsapp'));

    // Segundo mensaje: mismo remitente, distinto mid, timestamp posterior, otro cuerpo.
    $payload = json_decode(fx('whatsapp'), true);
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.HBgTEST00000002';
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = '1757009999';
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'Segundo mensaje, ¿me confirmas?';
    postWebhook('whatsapp', (string) json_encode($payload))->assertOk()->assertJsonPath('results.0.status', 'created');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $conv = SocialConversation::query()->first();
        expect($conv->unread_count)->toBe(2);
        expect($conv->last_message_preview)->toBe('Segundo mensaje, ¿me confirmas?');
        expect($conv->last_message_at->timestamp)->toBe(1757009999);
        expect(SocialMessage::query()->count())->toBe(2);
    });
});
