<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Jobs\ProcessWhatsAppInboundMedia;
use Modules\Social\Jobs\ProcessWhatsAppPostOnboarding;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialOutboundService;
use Modules\Social\Services\WhatsAppCoexistenceService;

/**
 * Coexistence-ready SIN Meta real: Embedded Signup (state de un solo uso, intercambio de
 * código server-side, credenciales cifradas), suscripción de WABA, syncs de contactos e
 * historial (una sola vez, rechazo 2593109 tolerado), y los webhooks smb_app_state_sync /
 * history / account_update. Todo mediante Http::fake y feature flags.
 */
const WC_SECRET = 'wc_test_secret';

beforeEach(function () {
    config([
        'social.graph_version' => 'v26.0',
        'social.app_secret' => WC_SECRET,
        'social.webhook_verify_token' => 'wc_verify',
        'social.meta_app_id' => 'APP_C1',
        'social.embedded_signup.enabled' => true,
        'social.embedded_signup.config_id' => 'CFG_C1',
    ]);
});

/**
 * @return array{0: Institution, 1: User, 2: SocialChannel, 3: SocialConversation}
 */
function wcCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    $channel = SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'external_id' => 'PHONE_C1',
        'credentials' => ['token' => 'WA_TOKEN', 'waba_id' => 'WABA_C1'],
    ]);
    $conversation = SocialConversation::factory()->create([
        'social_channel_id' => $channel->id,
        'provider' => 'whatsapp',
        'external_conversation_id' => '5215550777',
        'contact_external_id' => '5215550777',
        'contact_name' => 'Contacto Coex',
    ]);

    return [$institution, $user, $channel, $conversation];
}

function wcService(): WhatsAppCoexistenceService
{
    return app(WhatsAppCoexistenceService::class);
}

function wcPostWebhook(string $raw): TestResponse
{
    return test()->call('POST', '/api/social/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, WC_SECRET),
    ], $raw);
}

/**
 * @param  array<string, mixed>  $value
 */
function wcWebhook(string $field, array $value, string $waba = 'WABA_C1'): string
{
    return (string) json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => $waba, 'changes' => [['field' => $field, 'value' => $value]]]],
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $messages
 */
function wcHistoryValue(array $messages, string $thread = '5215550777', int $chunkOrder = 1, int $progress = 50, string $phase = '0'): array
{
    return [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '15551230000', 'phone_number_id' => 'PHONE_C1'],
        'history' => [[
            'metadata' => ['phase' => $phase, 'chunk_order' => $chunkOrder, 'progress' => $progress],
            'threads' => [['id' => $thread, 'messages' => $messages]],
        ]],
    ];
}

// ==================================================================================
// Embedded Signup
// ==================================================================================

/** Http::fake del signup completo: oauth + validación de activos + verificación + suscripción. */
function wcFakeSignup(string $waba = 'WABA_NEW', string $phone = 'PHONE_NEW', string $token = 'BIZTOKEN_1', bool $onBizApp = true): void
{
    Http::fake([
        'graph.facebook.com/v26.0/oauth/access_token' => Http::response(['access_token' => $token], 200),
        "graph.facebook.com/v26.0/{$waba}/phone_numbers*" => Http::response(['data' => [
            ['id' => $phone, 'display_phone_number' => '+52 155 5555 0000'],
        ]], 200),
        "graph.facebook.com/v26.0/{$phone}?*" => Http::response([
            'is_on_biz_app' => $onBizApp,
            'platform_type' => 'CLOUD_API',
            'id' => $phone,
        ], 200),
        "graph.facebook.com/v26.0/{$waba}/subscribed_apps" => Http::response(['success' => true], 200),
        // El post-onboarding (contactos+historial) corre after-response tras el signup.
        'graph.facebook.com/v26.0/*/smb_app_data' => Http::response(['success' => true, 'request_id' => 'REQ_AUTO'], 200),
    ]);
}

it('el callback del Embedded Signup valida el state, intercambia el código server-side y conecta el canal', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup();
    $state = wcService()->issueState($user->id, $institution->id);

    $res = test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state,
        'code' => 'AUTHCODE_1',
        'waba_id' => 'WABA_NEW',
        'phone_number_id' => 'PHONE_NEW',
        'display_phone_number' => '+52 155 5555 0000',
    ]);

    $res->assertOk()->assertJsonPath('status', 'connected');
    $channel = SocialChannel::query()->where('external_id', 'PHONE_NEW')->first();
    expect($channel)->not->toBeNull();
    expect($channel->credentials['token'])->toBe('BIZTOKEN_1');
    expect($channel->credentials['waba_id'])->toBe('WABA_NEW');
    expect($channel->connection_status)->toBe('connected_coexistence');
    expect($channel->connection_meta['waba_subscribed'])->toBeTrue();

    // El intercambio va server-side: code y secret en el CUERPO del POST, nunca en la URL.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'oauth/access_token')) {
            return false;
        }

        return ! str_contains($request->url(), 'AUTHCODE_1')
            && ! str_contains($request->url(), WC_SECRET)
            && $request['code'] === 'AUTHCODE_1'
            && $request['client_id'] === 'APP_C1';
    });
});

it('un state incorrecto (o reutilizado) se rechaza sin tocar nada', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup();
    $payload = ['code' => 'AUTHCODE_1', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW'];

    // State inventado.
    test()->actingAs($user)->post(route('social.wa-signup'), $payload + ['state' => 'estado_falso'])
        ->assertStatus(422)->assertJsonPath('status', 'invalid_state');

    // Replay: el state real solo vale UNA vez.
    $state = wcService()->issueState($user->id, $institution->id);
    test()->actingAs($user)->post(route('social.wa-signup'), $payload + ['state' => $state])->assertOk();
    test()->actingAs($user)->post(route('social.wa-signup'), $payload + ['state' => $state])
        ->assertStatus(422)->assertJsonPath('status', 'invalid_state');
});

it('el signup respeta el aislamiento por institución (state de otra institución → 422)', function () {
    [, $user] = wcCtx();
    $otra = Institution::factory()->create();
    Http::fake();

    // State emitido para OTRA institución: no puede consumirse desde la sesión actual.
    $state = wcService()->issueState($user->id, $otra->id);

    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'X', 'waba_id' => 'W', 'phone_number_id' => 'P',
    ])->assertStatus(422);

    expect(SocialChannel::query()->where('external_id', 'P')->exists())->toBeFalse();
});

it('con el feature flag apagado el endpoint responde 409 y no conecta nada', function () {
    [$institution, $user] = wcCtx();
    config(['social.embedded_signup.enabled' => false]);
    Http::fake();
    $state = wcService()->issueState($user->id, $institution->id);

    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'X', 'waba_id' => 'W', 'phone_number_id' => 'P',
    ])->assertStatus(409)->assertJsonPath('status', 'disabled');

    Http::assertNothingSent();
});

it('aborta si el phone_number_id NO pertenece al WABA del token (assets del navegador ≠ autoridad)', function () {
    [$institution, $user] = wcCtx();
    Http::fake([
        'graph.facebook.com/v26.0/oauth/access_token' => Http::response(['access_token' => 'BIZTOKEN_X'], 200),
        // El WABA del token solo contiene OTRO número: los asset IDs del navegador mienten.
        'graph.facebook.com/v26.0/WABA_NEW/phone_numbers*' => Http::response(['data' => [
            ['id' => 'PHONE_DE_OTRO', 'display_phone_number' => '+52 1 555 999'],
        ]], 200),
    ]);
    $state = wcService()->issueState($user->id, $institution->id);

    $res = test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'AUTHCODE_X', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
    ]);

    // Error sanitizado; el intercambio fue exitoso pero los activos no validan:
    // NO se persiste token, NO se crea/modifica canal.
    $res->assertStatus(422)->assertJsonPath('status', 'error');
    expect((string) $res->json('message'))->not->toContain('BIZTOKEN_X');
    expect(SocialChannel::query()->where('external_id', 'PHONE_NEW')->exists())->toBeFalse();
    $raw = (string) DB::table('social_channels')->pluck('credentials')->implode(' ');
    expect($raw)->not->toContain('BIZTOKEN_X');
});

it('sin confirmación is_on_biz_app/platform_type el canal queda en onboarding, no en connected', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup(onBizApp: false); // el número aún no está en el WhatsApp Business App
    $state = wcService()->issueState($user->id, $institution->id);

    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'C', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
    ])->assertOk();

    expect(SocialChannel::query()->where('external_id', 'PHONE_NEW')->value('connection_status'))
        ->toBe('onboarding'); // jamás se finge connected_coexistence
});

it('el intercambio OAuth usa el secreto ESPECÍFICO de WhatsApp cuando está configurado', function () {
    [$institution, $user] = wcCtx();
    config(['social.secrets.whatsapp' => 'WA_SPECIFIC_SECRET']);
    wcFakeSignup();
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    $state = wcService()->issueState($user->id, $institution->id);

    // Firmar el webhook no interviene aquí; el POST del panel usa sesión+CSRF.
    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'AUTHCODE_S', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
    ])->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'oauth/access_token')) {
            return false;
        }

        return $request['client_secret'] === 'WA_SPECIFIC_SECRET' // primario: secrets.whatsapp
            && ! str_contains($request->url(), 'WA_SPECIFIC_SECRET'); // jamás en la URL
    });
    expect(implode(' ', $logged))->not->toContain('WA_SPECIFIC_SECRET');
    expect(implode(' ', $logged))->not->toContain('AUTHCODE_S');
});

it('sin secreto específico, el intercambio OAuth cae al app_secret común (fallback)', function () {
    [$institution, $user] = wcCtx();
    expect(config('social.secrets.whatsapp'))->toBeNull(); // no configurado en este test
    wcFakeSignup();
    $state = wcService()->issueState($user->id, $institution->id);

    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'C', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/access_token')
        && $request['client_secret'] === WC_SECRET);
});

it('un error del intercambio OAuth devuelve mensaje sanitizado (sin secreto ni code)', function () {
    [$institution, $user] = wcCtx();
    config(['social.secrets.whatsapp' => 'WA_SPECIFIC_SECRET']);
    Http::fake(['graph.facebook.com/v26.0/oauth/access_token' => Http::response([
        'error' => ['message' => 'Invalid verification code format.', 'code' => 100],
    ], 400)]);
    $state = wcService()->issueState($user->id, $institution->id);

    $res = test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'AUTHCODE_BAD', 'waba_id' => 'W', 'phone_number_id' => 'P',
    ]);

    $res->assertStatus(422)->assertJsonPath('status', 'error');
    $message = (string) $res->json('message');
    expect($message)->not->toContain('WA_SPECIFIC_SECRET');
    expect($message)->not->toContain('AUTHCODE_BAD');
    expect(SocialChannel::query()->where('external_id', 'P')->exists())->toBeFalse();
});

it('un payload incompleto del signup se rechaza por validación (422)', function () {
    [$institution, $user] = wcCtx();
    Http::fake();
    $state = wcService()->issueState($user->id, $institution->id);

    test()->actingAs($user)->postJson(route('social.wa-signup'), [
        'state' => $state, 'waba_id' => 'W', 'phone_number_id' => 'P', // sin code
    ])->assertStatus(422)->assertJsonValidationErrors('code');

    Http::assertNothingSent();
});

it('repetir el signup del MISMO número actualiza el canal sin duplicarlo', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup();

    foreach (range(1, 2) as $i) {
        $state = wcService()->issueState($user->id, $institution->id);
        test()->actingAs($user)->post(route('social.wa-signup'), [
            'state' => $state, 'code' => 'C'.$i, 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
        ])->assertOk();
    }

    expect(SocialChannel::query()->where('external_id', 'PHONE_NEW')->count())->toBe(1);
});

it('el business token queda CIFRADO en la base de datos', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup(waba: 'W1', phone: 'P1', token: 'BIZTOKEN_SECRETO');
    $state = wcService()->issueState($user->id, $institution->id);
    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'C', 'waba_id' => 'W1', 'phone_number_id' => 'P1',
    ])->assertOk();

    $raw = (string) DB::table('social_channels')->where('external_id', 'P1')->value('credentials');
    expect($raw)->not->toContain('BIZTOKEN_SECRETO'); // ciphertext, jamás en claro
});

// ==================================================================================
// Suscripción y sincronizaciones (una sola vez)
// ==================================================================================

it('suscribe la WABA con Bearer y deja el resultado verificable', function () {
    [, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/v26.0/WABA_C1/subscribed_apps' => Http::response(['success' => true], 200)]);

    expect(wcService()->subscribeWaba($channel))->toBeTrue();
    expect($channel->refresh()->connection_meta['waba_subscribed'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v26.0/WABA_C1/subscribed_apps'
        && $request->hasHeader('Authorization', 'Bearer WA_TOKEN'));
});

it('si subscribed_apps falla NO se marca suscrito y el retry posterior funciona', function () {
    [, , $channel] = wcCtx();
    $phase = 'fail';
    Http::fake(function () use (&$phase) {
        return $phase === 'fail'
            ? Http::response(['error' => ['message' => 'server error', 'code' => 1]], 500)
            : Http::response(['success' => true], 200);
    });

    expect(wcService()->subscribeWaba($channel))->toBeFalse();
    expect($channel->refresh()->connection_meta['waba_subscribed'] ?? null)->toBeNull(); // sin marca

    $phase = 'ok';
    expect(wcService()->subscribeWaba($channel))->toBeTrue();
    expect($channel->refresh()->connection_meta['waba_subscribed'])->toBeTrue();
});

it('solicita el sync de contactos por smb_app_data y guarda request_id SOLO tras éxito', function () {
    [, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true, 'request_id' => 'REQ_CONTACTS_1'], 200)]);

    expect(wcService()->startContactsSync($channel))->toBe('requested');
    $meta = $channel->refresh()->connection_meta;
    expect($meta['contacts_sync_request_id'])->toBe('REQ_CONTACTS_1');
    expect($meta['contacts_sync_started_at'])->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v26.0/PHONE_C1/smb_app_data'
        && $request['messaging_product'] === 'whatsapp'
        && $request['sync_type'] === 'smb_app_state_sync');
});

it('solicita el sync de historial por smb_app_data y guarda request_id', function () {
    [, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true, 'request_id' => 'REQ_HIST_1'], 200)]);

    expect(wcService()->startHistorySync($channel))->toBe('requested');
    $meta = $channel->refresh()->connection_meta;
    expect($meta['history_sync_request_id'])->toBe('REQ_HIST_1');
    expect($meta['history_sync_status'])->toBe('requested');

    Http::assertSent(fn ($request) => $request['sync_type'] === 'history');
});

it('un fallo HTTP transitorio NO consume la oportunidad de sync: el retry funciona', function () {
    [, , $channel] = wcCtx();
    $phase = 'fail';
    Http::fake(function () use (&$phase) {
        return $phase === 'fail'
            ? Http::response(['error' => ['message' => 'rate limit', 'code' => 4]], 429)
            : Http::response(['success' => true, 'request_id' => 'REQ_RETRY_1'], 200);
    });

    // 500/429/timeout → 'failed' SIN marca (contacts e history por igual).
    expect(wcService()->startContactsSync($channel))->toBe('failed');
    expect(wcService()->startHistorySync($channel->refresh()))->toBe('failed');
    $meta = $channel->refresh()->connection_meta ?? [];
    expect($meta)->not->toHaveKey('contacts_sync_started_at');
    expect($meta)->not->toHaveKey('history_sync_started_at');

    // Retry posterior: éxito, marca y request_id.
    $phase = 'ok';
    expect(wcService()->startContactsSync($channel->refresh()))->toBe('requested');
    expect(wcService()->startHistorySync($channel->refresh()))->toBe('requested');
    expect($channel->refresh()->connection_meta['contacts_sync_request_id'])->toBe('REQ_RETRY_1');
});

it('ningún sync puede iniciarse dos veces tras el éxito', function () {
    [, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true, 'request_id' => 'REQ_X'], 200)]);

    expect(wcService()->startContactsSync($channel))->toBe('requested');
    expect(wcService()->startContactsSync($channel->refresh()))->toBe('already_requested');
    expect(wcService()->startHistorySync($channel))->toBe('requested');
    expect(wcService()->startHistorySync($channel->refresh()))->toBe('already_requested');

    Http::assertSentCount(2); // una llamada por sync, nunca más
});

it('si el usuario rechazó compartir historial (2593109) se marca declined y el canal sigue operativo', function () {
    [, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'History sharing declined on phone', 'code' => 2593109],
    ], 400)]);

    expect(wcService()->startHistorySync($channel))->toBe('declined');
    $channel->refresh();
    expect($channel->connection_meta['history_sync_status'])->toBe('declined');
    expect($channel->canSendViaApi())->toBeTrue();
    expect(wcService()->startHistorySync($channel))->toBe('already_requested'); // no reintenta solo
});

// ==================================================================================
// Post-onboarding automático (contactos → historial, after-response)
// ==================================================================================

it('el signup exitoso despacha el post-onboarding after-response', function () {
    [$institution, $user] = wcCtx();
    wcFakeSignup();
    Bus::fake();
    $state = wcService()->issueState($user->id, $institution->id);

    test()->actingAs($user)->post(route('social.wa-signup'), [
        'state' => $state, 'code' => 'C', 'waba_id' => 'WABA_NEW', 'phone_number_id' => 'PHONE_NEW',
    ])->assertOk();

    Bus::assertDispatchedAfterResponse(ProcessWhatsAppPostOnboarding::class);
});

it('el post-onboarding ejecuta PRIMERO contactos y DESPUÉS historial, con request_id', function () {
    [$institution, , $channel] = wcCtx();
    $order = [];
    Http::fake(function ($request) use (&$order) {
        if (str_contains($request->url(), 'smb_app_data')) {
            $order[] = $request['sync_type'];

            return Http::response(['success' => true, 'request_id' => 'REQ_'.count($order)], 200);
        }

        return Http::response(['success' => true], 200);
    });

    (new ProcessWhatsAppPostOnboarding($channel->id, $institution->id))->handle();

    expect($order)->toBe(['smb_app_state_sync', 'history']);
    $meta = $channel->refresh()->connection_meta;
    expect($meta['contacts_sync_request_id'])->toBe('REQ_1');
    expect($meta['history_sync_request_id'])->toBe('REQ_2');
    expect($meta['history_sync_status'])->toBe('requested');
});

it('history declined durante el post-onboarding no rompe el canal ya conectado', function () {
    [$institution, , $channel] = wcCtx();
    $channel->connection_status = 'connected_coexistence';
    $channel->save();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'smb_app_data') && $request['sync_type'] === 'history') {
            return Http::response(['error' => ['message' => 'declined on phone', 'code' => 2593109]], 400);
        }

        return Http::response(['success' => true, 'request_id' => 'REQ_C'], 200);
    });

    (new ProcessWhatsAppPostOnboarding($channel->id, $institution->id))->handle();

    $channel->refresh();
    expect($channel->connection_meta['contacts_sync_request_id'])->toBe('REQ_C'); // contactos OK
    expect($channel->connection_meta['history_sync_status'])->toBe('declined');
    expect($channel->connection_status)->toBe('connected_coexistence'); // intacto
    expect($channel->canSendViaApi())->toBeTrue();
});

it('un fallo transitorio en el post-onboarding no consume los syncs (retry del job posible)', function () {
    [$institution, , $channel] = wcCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops', 'code' => 1]], 500)]);

    (new ProcessWhatsAppPostOnboarding($channel->id, $institution->id))->handle();

    $meta = $channel->refresh()->connection_meta ?? [];
    expect($meta)->not->toHaveKey('contacts_sync_started_at');
    expect($meta)->not->toHaveKey('history_sync_started_at');
    expect($channel->refresh()->is_active)->toBeTrue(); // el canal no se invalida
});

// ==================================================================================
// UI del Embedded Signup (Canales)
// ==================================================================================

it('con el flag apagado la página de Canales NO carga el SDK y muestra Configuración pendiente', function () {
    [, $user] = wcCtx();
    config(['social.embedded_signup.enabled' => false]);

    \Livewire\Livewire::actingAs($user)->test(\Modules\Social\Livewire\Channels::class)
        ->assertSee(__('Configuración pendiente'))
        ->assertDontSee('connect.facebook.net');
});

it('con el flag activo la página carga el SDK con el flujo v4 (config_id + featureType)', function () {
    [, $user] = wcCtx();

    \Livewire\Livewire::actingAs($user)->test(\Modules\Social\Livewire\Channels::class)
        ->assertSee('connect.facebook.net', false)
        ->assertSee('whatsapp_business_app_onboarding', false)
        ->assertSee('override_default_response_type', false)
        ->assertDontSee('sessionInfoVersion');
});

it('signupState() emite un state válido de un solo uso, y null con el flujo apagado', function () {
    [$institution, $user] = wcCtx();
    $this->actingAs($user);
    app(CurrentInstitution::class)->set($institution->id);

    $component = new \Modules\Social\Livewire\Channels;
    $state = $component->signupState();
    expect($state)->toBeString()->not->toBe('');

    // Un solo uso: consumible exactamente una vez, para SU institución.
    expect(wcService()->consumeState($user->id, $state))->toBe($institution->id);
    expect(wcService()->consumeState($user->id, $state))->toBeNull();

    config(['social.embedded_signup.enabled' => false]);
    expect($component->signupState())->toBeNull();
});

// ==================================================================================
// Webhook smb_app_state_sync (agenda del teléfono)
// ==================================================================================

it('smb_app_state_sync add actualiza el nombre del contacto por wa_id', function () {
    [, , , $conversation] = wcCtx();
    Http::fake();

    $res = wcPostWebhook(wcWebhook('smb_app_state_sync', [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => 'PHONE_C1'],
        'state_sync' => [[
            'type' => 'contact',
            'action' => 'add',
            'contact' => ['full_name' => 'Valentina Rojas Agenda', 'first_name' => 'Valentina', 'phone_number' => '+52 1 555 077 7'],
        ]],
    ]));

    $res->assertOk()->assertJsonPath('coexistence.contacts', 1);
    expect($conversation->refresh()->contact_name)->toBe('Valentina Rojas Agenda');
});

it('smb_app_state_sync remove NO borra la conversación ni sus mensajes', function () {
    [, , , $conversation] = wcCtx();
    $before = SocialConversation::query()->count();
    Http::fake();

    wcPostWebhook(wcWebhook('smb_app_state_sync', [
        'metadata' => ['phone_number_id' => 'PHONE_C1'],
        'state_sync' => [[
            'type' => 'contact', 'action' => 'remove',
            'contact' => ['full_name' => 'Contacto Coex', 'phone_number' => '5215550777'],
        ]],
    ]))->assertOk();

    expect(SocialConversation::query()->count())->toBe($before);
    expect($conversation->refresh()->contact_name)->toBe('Contacto Coex');
});

// ==================================================================================
// Webhook history (backfill idempotente por wamid)
// ==================================================================================

it('history ingiere inbound y outbound sin subir unread y es idempotente', function () {
    [, , , $conversation] = wcCtx();
    Http::fake();

    $messages = [
        ['from' => '5215550777', 'id' => 'wamid.HIST_IN_1', 'timestamp' => '1756000000', 'type' => 'text', 'text' => ['body' => 'Hola, info del MBA']],
        ['from' => '15551230000', 'id' => 'wamid.HIST_OUT_1', 'timestamp' => '1756000100', 'type' => 'text', 'text' => ['body' => 'Claro, te comparto']],
    ];
    $raw = wcWebhook('history', wcHistoryValue($messages));

    wcPostWebhook($raw)->assertOk()->assertJsonPath('coexistence.history', 2);

    $in = SocialMessage::query()->where('external_message_id', 'wamid.HIST_IN_1')->first();
    $out = SocialMessage::query()->where('external_message_id', 'wamid.HIST_OUT_1')->first();
    expect($in->direction)->toBe('inbound');
    expect($in->sender_type)->toBe('contact');
    expect($out->direction)->toBe('outbound');
    expect($out->sender_type)->toBe('app');
    expect($conversation->refresh()->unread_count)->toBe(0); // historial: sin no-leídos

    // Reintento del webhook: idempotente por wamid (0 nuevos; responde 200 igualmente).
    wcPostWebhook($raw)->assertOk();
    expect(SocialMessage::query()->count())->toBe(2);
});

it('chunks de history fuera de orden no retroceden el preview de la conversación', function () {
    [, , , $conversation] = wcCtx();
    // Conversación sin actividad previa: el backfill parte de cero.
    $conversation->last_message_at = null;
    $conversation->last_message_preview = null;
    $conversation->save();
    Http::fake();

    // Chunk 2 (mensaje MÁS RECIENTE) llega primero.
    wcPostWebhook(wcWebhook('history', wcHistoryValue([
        ['from' => '5215550777', 'id' => 'wamid.HIST_NEW', 'timestamp' => '1757000000', 'type' => 'text', 'text' => ['body' => 'mensaje reciente']],
    ], chunkOrder: 2, progress: 100)))->assertOk();

    // Chunk 1 (mensaje ANTIGUO) llega después.
    wcPostWebhook(wcWebhook('history', wcHistoryValue([
        ['from' => '5215550777', 'id' => 'wamid.HIST_OLD', 'timestamp' => '1750000000', 'type' => 'text', 'text' => ['body' => 'mensaje antiguo']],
    ], chunkOrder: 1, progress: 50)))->assertOk();

    $conversation->refresh();
    expect(SocialMessage::query()->count())->toBe(2);
    expect($conversation->last_message_preview)->toBe('mensaje reciente'); // no retrocede
});

it('la media del historial se procesa after-response (sin bloquear el webhook)', function () {
    wcCtx();
    Http::fake();
    Bus::fake();

    wcPostWebhook(wcWebhook('history', wcHistoryValue([
        ['from' => '5215550777', 'id' => 'wamid.HIST_MEDIA', 'timestamp' => '1756000000', 'type' => 'image',
            'image' => ['id' => 'MEDIA_HIST_1', 'mime_type' => 'image/jpeg', 'caption' => 'foto histórica']],
    ])))->assertOk();

    Http::assertNothingSent(); // cero descargas dentro del ciclo del webhook
    Bus::assertDispatchedAfterResponse(ProcessWhatsAppInboundMedia::class);
    $att = SocialMessage::query()->where('external_message_id', 'wamid.HIST_MEDIA')->first()->attachments[0];
    expect($att['provider_media_id'])->toBe('MEDIA_HIST_1');
});

// ==================================================================================
// Webhook account_update (offboarded / reconnected)
// ==================================================================================

it('ACCOUNT_OFFBOARDED marca el canal, bloquea envíos API y CONSERVA credenciales', function () {
    [, $user, $channel, $conversation] = wcCtx();
    Http::fake();

    wcPostWebhook(wcWebhook('account_update', [
        'phone_number' => '+15551230000',
        'event' => 'ACCOUNT_OFFBOARDED',
    ]))->assertOk()->assertJsonPath('coexistence.account', 1);

    $channel->refresh();
    expect($channel->connection_status)->toBe('offboarded');
    expect($channel->credentials['token'])->toBe('WA_TOKEN'); // NO se borran credenciales

    // El envío por API queda bloqueado sin llamar a la red.
    $message = app(SocialOutboundService::class)->send($conversation->refresh(), 'hola', $user);
    expect($message->status)->toBe('failed');
    Http::assertNothingSent();
});

it('ACCOUNT_RECONNECTED restaura el estado y vuelve a permitir el envío', function () {
    [, $user, $channel, $conversation] = wcCtx();
    wcService()->markOffboarded($channel);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BACK_1']]], 200)]);

    wcPostWebhook(wcWebhook('account_update', [
        'phone_number' => '+15551230000',
        'event' => 'ACCOUNT_RECONNECTED',
    ]))->assertOk();

    expect($channel->refresh()->connection_status)->toBe('connected_coexistence');
    $message = app(SocialOutboundService::class)->send($conversation->refresh(), 'de vuelta', $user);
    expect($message->status)->toBe('sent');
});

// ==================================================================================
// Regresión: los echoes de coexistencia conviven con lo nuevo
// ==================================================================================

it('smb_message_echoes sigue funcionando junto a history (sin duplicados)', function () {
    [, , $channel] = wcCtx();
    $channel->external_id = 'demo_wa_phone'; // el fixture del eco usa demo_wa_phone
    $channel->save();
    Http::fake();

    $echoRaw = (string) file_get_contents(__DIR__.'/../Fixtures/whatsapp_echo.json');
    wcPostWebhook($echoRaw)->assertOk()->assertJsonPath('results.0.status', 'created');

    // El MISMO mensaje llega después por history: idempotente por wamid, no duplica.
    wcPostWebhook(wcWebhook('history', [
        'metadata' => ['phone_number_id' => 'demo_wa_phone'],
        'history' => [[
            'metadata' => ['phase' => '0', 'chunk_order' => 1, 'progress' => 100],
            'threads' => [['id' => '5215559990001', 'messages' => [
                ['from' => '15551230000', 'id' => 'wamid.ECHO00000001', 'timestamp' => '1757002000', 'type' => 'text', 'text' => ['body' => 'duplicado del eco']],
            ]]],
        ]],
    ], 'WABA_1234567890'))->assertOk();

    expect(SocialMessage::query()->where('external_message_id', 'wamid.ECHO00000001')->count())->toBe(1);
    $echo = SocialMessage::query()->where('external_message_id', 'wamid.ECHO00000001')->first();
    expect($echo->sender_type)->toBe('app');
});
