<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Models\McpOauthAccessToken;
use Modules\Mcp\Services\OAuthService;

/**
 * OAuth 2.1 (DCR + Authorization Code + PKCE S256 + refresh rotatorio) para ChatGPT sobre
 * el MCP existente, SIN romper el Bearer estático de Claude Code ni las 20 tools.
 */
function adminUser(): User
{
    // La conexión OAuth es GLOBAL → requiere super admin para autorizarla.
    $institution = Institution::factory()->create();

    return User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'is_super_admin' => true]);
}

function svc(): OAuthService
{
    return app(OAuthService::class);
}

function pkcePair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

function registerClient(string $redirect = 'https://chatgpt.com/callback'): string
{
    return test()->postJson('/oauth/register', [
        'redirect_uris' => [$redirect], 'client_name' => 'ChatGPT', 'token_endpoint_auth_method' => 'none',
    ])->assertStatus(201)->json('client_id');
}

/** Flujo completo hasta obtener la respuesta de /token. */
function authFlow(array $tokenOverride = [], array $authOverride = []): array
{
    $clientId = registerClient();
    [$verifier, $challenge] = pkcePair();
    $resource = svc()->resource();

    $auth = array_merge([
        'client_id' => $clientId, 'redirect_uri' => 'https://chatgpt.com/callback', 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'mcp:read offline_access',
        'state' => 'st4te', 'resource' => $resource, 'decision' => 'approve',
    ], $authOverride);

    $redirect = test()->actingAs(adminUser())->post('/oauth/authorize', $auth)->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $q);

    $tokenReq = array_merge([
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $q['code'] ?? '',
        'code_verifier' => $verifier, 'redirect_uri' => 'https://chatgpt.com/callback', 'resource' => $resource,
    ], $tokenOverride);

    $token = test()->postJson('/oauth/token', $tokenReq);

    return compact('clientId', 'verifier', 'challenge', 'resource') + ['code' => $q['code'] ?? '', 'token' => $token];
}

function mcp(string $token, array $body): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp', $body);
}

function bearerClient(string $profile = 'technical', bool $allowWrite = false, ?int $institution = null): array
{
    $plain = 'mcp_'.Str::random(48);
    $client = McpClient::query()->create([
        'name' => 'claude-code', 'assistant_type' => 'claude_code', 'profile' => $profile,
        'allow_write' => $allowWrite, 'auth_kind' => 'bearer', 'token_hash' => hash('sha256', $plain),
        'institution_id' => $institution, 'is_active' => true,
    ]);

    return [$client, $plain];
}

// ============================================================ metadata

it('publica Protected Resource Metadata correcta', function () {
    $r = test()->getJson('/.well-known/oauth-protected-resource')->assertOk();
    expect($r->json('resource'))->toBe(svc()->resource())
        ->and($r->json('authorization_servers'))->toBe([svc()->issuer()])
        ->and($r->json('scopes_supported'))->toContain('mcp:read', 'offline_access');
});

it('publica Authorization Server Metadata sin declarar capacidades no implementadas', function () {
    $r = test()->getJson('/.well-known/oauth-authorization-server')->assertOk();
    expect($r->json('issuer'))->toBe(svc()->issuer())
        ->and($r->json('authorization_endpoint'))->toBe(svc()->issuer().'/oauth/authorize')
        ->and($r->json('token_endpoint'))->toBe(svc()->issuer().'/oauth/token')
        ->and($r->json('registration_endpoint'))->toBe(svc()->issuer().'/oauth/register')
        ->and($r->json('response_types_supported'))->toBe(['code'])
        ->and($r->json('grant_types_supported'))->toContain('authorization_code', 'refresh_token')
        ->and($r->json('code_challenge_methods_supported'))->toBe(['S256'])
        ->and($r->json('token_endpoint_auth_methods_supported'))->toBe(['none']);
    // NO se anuncia CIMD.
    $r->assertJsonMissingPath('client_id_metadata_document_supported');
});

// ============================================================ DCR

it('DCR válido registra un public client sin secreto', function () {
    $r = test()->postJson('/oauth/register', ['redirect_uris' => ['https://chatgpt.com/callback']])->assertStatus(201);
    expect($r->json('client_id'))->toStartWith('mcpc_')
        ->and($r->json('token_endpoint_auth_method'))->toBe('none');
    $r->assertJsonMissingPath('client_secret');
});

it('DCR rechaza redirect_uri no HTTPS / host desconocido / localhost', function () {
    foreach (['http://chatgpt.com/cb', 'https://evil.example.com/cb', 'http://localhost/cb', 'javascript:alert(1)'] as $bad) {
        test()->postJson('/oauth/register', ['redirect_uris' => [$bad]])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }
});

// ============================================================ authorize (login + admin)

it('/authorize exige sesión (guest → login) y admin (marketing → 403)', function () {
    $clientId = registerClient();
    [, $challenge] = pkcePair();
    $q = ['client_id' => $clientId, 'redirect_uri' => 'https://chatgpt.com/callback', 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => svc()->resource()];

    test()->get('/oauth/authorize?'.http_build_query($q))->assertRedirect('/login');

    $marketing = User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'marketing']);
    test()->actingAs($marketing)->get('/oauth/authorize?'.http_build_query($q))->assertForbidden();
});

it('SOLO un super admin puede aprobar una conexión Global (admin institucional rechazado)', function () {
    $clientId = registerClient();
    [, $challenge] = pkcePair();
    $params = [
        'client_id' => $clientId, 'redirect_uri' => 'https://chatgpt.com/callback', 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'mcp:read offline_access',
        'state' => 's', 'resource' => svc()->resource(), 'decision' => 'approve',
    ];

    // Admin institucional (no super): rechazado y NO crea ningún mcp_client.
    $instAdmin = User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'admin', 'is_super_admin' => false]);
    test()->actingAs($instAdmin)->post('/oauth/authorize', $params)->assertForbidden();
    expect(McpClient::query()->where('auth_kind', 'oauth')->count())->toBe(0);

    // Super admin: aprueba (redirect con code) y crea exactamente un mcp_client Global.
    $redirect = test()->actingAs(adminUser())->post('/oauth/authorize', $params)->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $q);
    expect($q['code'] ?? null)->not->toBeNull()
        ->and(McpClient::query()->where('auth_kind', 'oauth')->count())->toBe(1);
    $mc = McpClient::query()->where('auth_kind', 'oauth')->first();
    expect($mc->institution_id)->toBeNull()->and($mc->effectiveProfile())->toBe('inspection')->and($mc->allow_write)->toBeFalse();
});

it('la reautorización del mismo OAuth client NO duplica el mcp_client operativo', function () {
    $clientId = registerClient();
    $resource = svc()->resource();
    $superAdmin = adminUser();

    $authorize = function () use ($clientId, $resource, $superAdmin): void {
        [, $challenge] = pkcePair();
        test()->actingAs($superAdmin)->post('/oauth/authorize', [
            'client_id' => $clientId, 'redirect_uri' => 'https://chatgpt.com/callback', 'response_type' => 'code',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'mcp:read offline_access',
            'state' => 's', 'resource' => $resource, 'decision' => 'approve',
        ])->assertRedirect();
    };

    $authorize();
    $authorize();

    expect(McpClient::query()->where('auth_kind', 'oauth')->count())->toBe(1);
});

// ============================================================ Authorization Code + PKCE

it('flujo PKCE S256 completo emite access+refresh y el token funciona en /api/mcp', function () {
    $f = authFlow();
    $f['token']->assertOk();
    expect($f['token']->json('token_type'))->toBe('Bearer')
        ->and($f['token']->json('access_token'))->toStartWith('mcpoauth_')
        ->and($f['token']->json('refresh_token'))->toStartWith('mcprt_')
        ->and($f['token']->json('scope'))->toContain('mcp:read');

    // El access token autentica el MCP y lista solo tools de lectura.
    $list = mcp($f['token']->json('access_token'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    $names = collect($list->json('result.tools'))->pluck('name');
    expect($names)->toContain('crm_overview')->and($names)->not->toContain('crm_record_create');
});

it('rechaza PKCE con verifier incorrecto', function () {
    $f = authFlow(['code_verifier' => 'verifier-incorrecto-que-no-corresponde']);
    $f['token']->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('el authorization code es de un solo uso', function () {
    $f = authFlow();
    $f['token']->assertOk();
    // Reintentar con el mismo code:
    test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $f['clientId'], 'code' => $f['code'],
        'code_verifier' => $f['verifier'], 'redirect_uri' => 'https://chatgpt.com/callback', 'resource' => $f['resource'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('rechaza resource incorrecto en /token', function () {
    $f = authFlow(['resource' => 'https://otro-servidor.example.com/api/mcp']);
    $f['token']->assertStatus(400)->assertJsonPath('error', 'invalid_target');
});

// ============================================================ access token / refresh

it('rechaza un access token expirado en /api/mcp', function () {
    [$client] = bearerClient(); // reutilizamos un mcp_client cualquiera como contexto
    $plain = 'mcpoauth_'.Str::random(48);
    McpOauthAccessToken::query()->create([
        'token_hash' => hash('sha256', $plain), 'client_id' => 'mcpc_x', 'mcp_client_id' => $client->id,
        'scope' => 'mcp:read', 'resource' => svc()->resource(), 'expires_at' => now()->subMinute(), 'created_at' => now()->subHour(),
    ]);

    mcp($plain, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401)->assertHeader('WWW-Authenticate');
});

it('refresh_token válido emite un nuevo access token', function () {
    $f = authFlow();
    $refresh = $f['token']->json('refresh_token');

    $r = test()->postJson('/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $f['clientId'], 'refresh_token' => $refresh, 'resource' => $f['resource'],
    ])->assertOk();
    expect($r->json('access_token'))->toStartWith('mcpoauth_')
        ->and($r->json('refresh_token'))->toStartWith('mcprt_')
        ->and($r->json('refresh_token'))->not->toBe($refresh); // rotado
});

it('rechaza el reuso de un refresh token ya rotado', function () {
    $f = authFlow();
    $refresh = $f['token']->json('refresh_token');
    test()->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $f['clientId'], 'refresh_token' => $refresh, 'resource' => $f['resource']])->assertOk();

    // Segundo uso del MISMO refresh → rechazado.
    test()->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $f['clientId'], 'refresh_token' => $refresh, 'resource' => $f['resource']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

// ============================================================ scopes / write

it('bloquea mcp:write cuando el mcp_client tiene allow_write=false (perfil)', function () {
    $f = authFlow();
    // El contexto OAuth es inspection/allow_write=false → tool de escritura denegada.
    $call = mcp($f['token']->json('access_token'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'crm_record_create', 'arguments' => ['model' => 'App.User', 'data' => []]],
    ])->assertOk();
    expect($call->json('result.isError'))->toBeTrue();
});

it('scope insuficiente: el token sin mcp:write no ejecuta una tool de escritura aunque el mcp_client pudiera', function () {
    // mcp_client que SÍ puede escribir, pero token con scope solo mcp:read.
    $client = McpClient::query()->create([
        'name' => 'oauth-tech', 'assistant_type' => 'chatgpt', 'profile' => McpClient::PROFILE_TECHNICAL,
        'allow_write' => true, 'auth_kind' => 'oauth', 'token_hash' => hash('sha256', 'x'.Str::random(40)),
        'institution_id' => null, 'is_active' => true,
    ]);
    $plain = 'mcpoauth_'.Str::random(48);
    McpOauthAccessToken::query()->create([
        'token_hash' => hash('sha256', $plain), 'client_id' => 'mcpc_y', 'mcp_client_id' => $client->id,
        'scope' => 'mcp:read', 'resource' => svc()->resource(), 'expires_at' => now()->addHour(), 'created_at' => now(),
    ]);

    $call = mcp($plain, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'crm_execute', 'arguments' => []],
    ])->assertOk();
    expect($call->json('result.isError'))->toBeTrue()
        ->and($call->json('result._meta.mcp/www_authenticate.error'))->toBe('insufficient_scope');
});

// ============================================================ revocación

it('un access token revocado deja de autenticar', function () {
    $f = authFlow();
    $access = $f['token']->json('access_token');
    mcp($access, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();

    test()->postJson('/oauth/revoke', ['token' => $access])->assertOk();
    mcp($access, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
});

// ============================================================ Claude Code intacto

it('el Bearer estático de Claude Code sigue autenticando y ve sus 20 tools con securitySchemes', function () {
    [$client, $plain] = bearerClient('technical', true); // technical + allow_write
    $list = mcp($plain, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    $tools = collect($list->json('result.tools'));

    expect($tools)->toHaveCount(20)
        ->and($tools->firstWhere('name', 'crm_overview')['securitySchemes'])->toBe([['type' => 'oauth2', 'scopes' => ['mcp:read']]])
        ->and($tools->firstWhere('name', 'crm_record_create')['securitySchemes'])->toBe([['type' => 'oauth2', 'scopes' => ['mcp:write']]])
        ->and($tools->firstWhere('name', 'crm_overview')['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools->firstWhere('name', 'crm_record_delete')['annotations']['destructiveHint'])->toBeTrue();

    // Su estado no cambia (sigue technical + allow_write + sin institución).
    $client->refresh();
    expect($client->auth_kind)->toBe('bearer')->and($client->allow_write)->toBeTrue()->and($client->institution_id)->toBeNull();
});

it('tools/list expone el descriptor compatible con OpenAI Apps SDK (securitySchemes + espejo _meta idéntico)', function () {
    [, $plain] = bearerClient('technical', true); // 20 tools (read + write)
    $tools = collect(mcp($plain, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'));

    expect($tools)->toHaveCount(20);
    foreach ($tools as $t) {
        expect($t)->toHaveKeys(['name', 'description', 'inputSchema', 'annotations', 'securitySchemes', '_meta'])
            ->and($t['inputSchema']['type'])->toBe('object')
            // OpenAI Apps SDK exige que securitySchemes sea una LISTA de esquemas
            // (tagged-union), no un objeto: nuestras tools llevan exactamente un esquema OAuth.
            ->and($t['securitySchemes'])->toBeArray()->toBeList()->toHaveCount(1)
            ->and($t['securitySchemes'][0]['type'])->toBe('oauth2')
            // Espejo IDÉNTICO en _meta.securitySchemes (misma lista, requisito del descriptor).
            ->and($t['_meta']['securitySchemes'])->toBe($t['securitySchemes'])
            ->and($t['annotations'])->toHaveKey('readOnlyHint');
    }

    expect($tools->firstWhere('name', 'crm_overview')['securitySchemes'][0]['scopes'])->toBe(['mcp:read'])
        ->and($tools->firstWhere('name', 'crm_record_create')['securitySchemes'][0]['scopes'])->toBe(['mcp:write']);
});

it('el 401 sin token incluye resource_metadata en WWW-Authenticate', function () {
    $r = test()->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    expect($r->headers->get('WWW-Authenticate'))->toContain('resource_metadata=', '/.well-known/oauth-protected-resource');
});
