<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Models\McpClient;
use Modules\Social\Models\SocialChannel;

/**
 * Servidor MCP privado (POST /api/mcp, JSON-RPC 2.0, Bearer): protocolo,
 * autenticación, herramientas transversales, redacción de secretos,
 * aislamiento multi-institución, confirmación de destructivas y auditoría.
 */

/**
 * @return array{0: Institution, 1: string} [institución, token en claro]
 */
function mcpCtx(bool $global = true): array
{
    $institution = Institution::factory()->create();
    $token = 'mcp_'.str_repeat('t', 44).uniqid();
    // Cliente técnico con escritura HABILITADA explícitamente: estos tests
    // ejercitan tools de lectura y de escritura (la escritura nunca es default).
    McpClient::query()->create([
        'name' => 'test-'.uniqid(),
        'profile' => 'technical',
        'allow_write' => true,
        'token_hash' => hash('sha256', $token),
        'institution_id' => $global ? null : $institution->id,
        'is_active' => true,
    ]);

    return [$institution, $token];
}

function mcpRpc(string $token, string $method, array $params = [], mixed $id = 1): TestResponse
{
    return test()->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ], ['Authorization' => 'Bearer '.$token]);
}

/**
 * Llama a una tool y devuelve [payload decodificado, isError].
 *
 * @return array{0: array<string, mixed>|null, 1: bool, 2: string}
 */
function mcpTool(string $token, string $name, array $arguments = []): array
{
    $res = mcpRpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    $res->assertOk();
    $text = (string) $res->json('result.content.0.text');
    $isError = (bool) $res->json('result.isError');
    $decoded = json_decode($text, true);

    return [is_array($decoded) ? $decoded : null, $isError, $text];
}

// ==================================================================================
// Autenticación y protocolo
// ==================================================================================

it('sin token o con token inválido responde 401 y no revela nada', function () {
    mcpCtx();

    test()->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401);
    test()->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer mcp_invalido'])
        ->assertStatus(401);
});

it('un cliente revocado pierde el acceso de inmediato', function () {
    [, $token] = mcpCtx();
    mcpRpc($token, 'ping')->assertOk();

    McpClient::query()->update(['is_active' => false]);
    mcpRpc($token, 'ping')->assertStatus(401);
});

it('initialize negocia protocolo y tools/list expone el catálogo completo', function () {
    [, $token] = mcpCtx();

    $init = mcpRpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test']]);
    $init->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'mca-crm-mcp');

    $tools = mcpRpc($token, 'tools/list')->json('result.tools');
    expect(count($tools))->toBeGreaterThanOrEqual(19);
    expect(collect($tools)->pluck('name'))->toContain('crm_overview', 'crm_query', 'crm_execute', 'crm_code_search', 'crm_git_status');
});

it('las notificaciones responden 202 y los batches se rechazan', function () {
    [, $token] = mcpCtx();

    test()->call('POST', '/api/mcp', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/json',
    ], (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))
        ->assertStatus(202);

    test()->call('POST', '/api/mcp', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/json',
    ], (string) json_encode([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]))
        ->assertOk()->assertJsonPath('error.code', -32600);
});

it('GET al endpoint responde 405 (solo POST JSON-RPC)', function () {
    [, $token] = mcpCtx();

    test()->getJson('/api/mcp', ['Authorization' => 'Bearer '.$token])->assertStatus(405);
});

// ==================================================================================
// Descubrimiento
// ==================================================================================

it('crm_overview entrega panorama transversal del CRM', function () {
    [, $token] = mcpCtx();

    [$data, $isError] = mcpTool($token, 'crm_overview');

    expect($isError)->toBeFalse();
    expect($data['app']['laravel'])->not->toBeNull();
    expect($data['modules'])->toHaveKey('Social');
    expect($data['modules'])->toHaveKey('Mcp');
    expect($data['counts']['models'])->toBeGreaterThan(20);
    expect($data['client']['scope'])->toBe('global');
});

it('crm_search localiza una funcionalidad en código, rutas y modelos', function () {
    [, $token] = mcpCtx();

    [$data, $isError] = mcpTool($token, 'crm_search', ['term' => 'WhatsAppMessageSender']);

    expect($isError)->toBeFalse();
    expect(count($data['code']))->toBeGreaterThan(0);
    // Algún hit debe apuntar a la implementación real en el módulo Social.
    expect(collect($data['code'])->pluck('file')->contains(fn (string $f) => str_contains($f, 'Modules/Social')))->toBeTrue();
});

it('crm_models resuelve un modelo con tabla, fillable y relaciones', function () {
    [, $token] = mcpCtx();

    [$data, $isError] = mcpTool($token, 'crm_models', ['model' => 'Lead']);

    expect($isError)->toBeFalse();
    expect($data['class'])->toBe(Lead::class);
    expect($data['table'])->toBe('leads');
    expect($data['institution_scoped'])->toBeTrue();
    expect(collect($data['relations'])->pluck('name'))->toContain('contact');
});

it('crm_schema describe columnas e índices y rechaza tablas desconocidas', function () {
    [, $token] = mcpCtx();

    [$data] = mcpTool($token, 'crm_schema', ['table' => 'social_channels']);
    expect(collect($data['columns'])->pluck('name'))->toContain('external_id', 'connection_status');

    [, $isError] = mcpTool($token, 'crm_schema', ['table' => 'no_existe']);
    expect($isError)->toBeTrue();
});

it('crm_config devuelve valores normales y ENMASCARA los secretos', function () {
    [, $token] = mcpCtx();
    config(['social.app_secret' => 'SUPERSECRETO_123456']);

    [$data] = mcpTool($token, 'crm_config', ['key' => 'social.graph_version']);
    expect($data['value'])->toBe(config('social.graph_version'));

    [, , $raw] = mcpTool($token, 'crm_config', ['key' => 'social.app_secret']);
    expect($raw)->not->toContain('SUPERSECRETO_123456');
    expect($raw)->toContain('configured');
});

// ==================================================================================
// Datos: query + CRUD + aislamiento + redacción
// ==================================================================================

it('crm_query lee con filtros y redacta columnas de credenciales', function () {
    [$institution, $token] = mcpCtx();
    app(CurrentInstitution::class)->runFor($institution->id, function (): void {
        SocialChannel::factory()->create(['provider' => 'whatsapp', 'credentials' => ['token' => 'WA_TOKEN_REAL']]);
    });

    [$data, $isError, $raw] = mcpTool($token, 'crm_query', [
        'table' => 'social_channels',
        'where' => [['provider', '=', 'whatsapp']],
        'institution_id' => $institution->id,
    ]);

    expect($isError)->toBeFalse();
    expect($data['count'])->toBe(1);
    expect($raw)->not->toContain('WA_TOKEN_REAL'); // ni en claro ni cifrado completo
});

it('crm_query agrega con group_by', function () {
    [$institution, $token] = mcpCtx();
    app(CurrentInstitution::class)->runFor($institution->id, function (): void {
        Lead::factory()->count(2)->create(['status' => 'new']);
        Lead::factory()->create(['status' => 'qualified']);
    });

    [$data] = mcpTool($token, 'crm_query', [
        'table' => 'leads',
        'aggregate' => ['fn' => 'count', 'group_by' => 'status'],
        'institution_id' => $institution->id,
    ]);

    $byStatus = collect($data['rows'])->pluck('value', 'group_value');
    expect((int) $byStatus['new'])->toBe(2);
    expect((int) $byStatus['qualified'])->toBe(1);
});

it('un cliente ACOTADO queda aislado en su institución y no puede pedir otra', function () {
    [$mine, $token] = mcpCtx(global: false);
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($mine->id, fn () => Lead::factory()->create());
    app(CurrentInstitution::class)->runFor($other->id, fn () => Lead::factory()->count(3)->create());

    [$data] = mcpTool($token, 'crm_query', ['table' => 'leads']);
    expect($data['count'])->toBe(1); // solo su institución

    [, $isError, $raw] = mcpTool($token, 'crm_query', ['table' => 'leads', 'institution_id' => $other->id]);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('acotado');
});

it('un cliente GLOBAL consulta transversalmente y también por institución', function () {
    [$one, $token] = mcpCtx();
    $two = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($one->id, fn () => Lead::factory()->create());
    app(CurrentInstitution::class)->runFor($two->id, fn () => Lead::factory()->count(2)->create());

    [$all] = mcpTool($token, 'crm_query', ['table' => 'leads']);
    [$scoped] = mcpTool($token, 'crm_query', ['table' => 'leads', 'institution_id' => $two->id]);

    expect($all['count'])->toBe(3);   // transversal deliberado (cliente global)
    expect($scoped['count'])->toBe(2);
});

it('crm_record_create crea vía Eloquent con institución y crm_record_update modifica', function () {
    [$institution, $token] = mcpCtx();

    // Modelo acotado sin institución → error claro.
    [, $isError, $raw] = mcpTool($token, 'crm_record_create', [
        'model' => 'Crm.Contact',
        'data' => ['first_name' => 'Eva', 'last_name' => 'Prueba', 'email' => 'eva@example.test'],
    ]);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('institution_id');

    [$created] = mcpTool($token, 'crm_record_create', [
        'model' => 'Crm.Contact',
        'data' => ['first_name' => 'Eva', 'last_name' => 'Prueba', 'email' => 'eva@example.test', 'preferred_language' => 'es', 'country' => 'MX'],
        'institution_id' => $institution->id,
    ]);
    expect($created['created'])->toBeTrue();
    $id = (int) $created['id'];

    [$updated] = mcpTool($token, 'crm_record_update', [
        'model' => 'Crm.Contact',
        'id' => $id,
        'data' => ['first_name' => 'Evelia'],
        'institution_id' => $institution->id,
    ]);
    expect($updated['changed'])->toBe(['first_name']);

    $contact = app(CurrentInstitution::class)->runFor($institution->id, fn () => Contact::query()->find($id));
    expect($contact->first_name)->toBe('Evelia');
});

it('crm_record_delete exige confirm: true (y con él elimina)', function () {
    [$institution, $token] = mcpCtx();
    $contact = app(CurrentInstitution::class)->runFor($institution->id, fn () => Contact::factory()->create());

    [, $isError, $raw] = mcpTool($token, 'crm_record_delete', [
        'model' => 'Crm.Contact', 'id' => $contact->id, 'institution_id' => $institution->id,
    ]);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('confirm');

    [$deleted] = mcpTool($token, 'crm_record_delete', [
        'model' => 'Crm.Contact', 'id' => $contact->id, 'confirm' => true, 'institution_id' => $institution->id,
    ]);
    expect($deleted['deleted'])->toBeTrue();
});

it('los campos de contraseña/hash y los modelos del propio MCP no son escribibles', function () {
    [$institution, $token] = mcpCtx();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    [, $err1, $raw1] = mcpTool($token, 'crm_record_update', [
        'model' => 'App.User', 'id' => $user->id, 'data' => ['password' => 'hack'], 'institution_id' => $institution->id,
    ]);
    expect($err1)->toBeTrue();
    expect($raw1)->toContain('no es escribible');

    [, $err2, $raw2] = mcpTool($token, 'crm_record_update', [
        'model' => 'Mcp.McpClient', 'id' => 1, 'data' => ['is_active' => false],
    ]);
    expect($err2)->toBeTrue();
    expect($raw2)->toContain('mcp:client');
});
