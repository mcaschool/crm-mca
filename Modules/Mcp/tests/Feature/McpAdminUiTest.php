<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Livewire\Admin;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Services\McpClientManager;

/**
 * Pantalla de administración ChatGPT/MCP (Configuración → Integraciones):
 * gate de acceso, wizard de creación con token de un solo uso, rotar/revocar,
 * prueba de servidor, actividad y reutilización de McpClientManager. Nunca
 * expone hashes ni el token tras cerrarse.
 */
function adminCtx(bool $super = true): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create([
        'institution_id' => $institution->id,
        'role' => 'admin',
        'is_super_admin' => $super,
    ]);

    return [$institution, $user];
}

it('solo un administrador puede abrir la pantalla MCP', function () {
    [$inst, $admin] = adminCtx();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    // Marketing no gestiona integraciones → 403; el administrador sí entra.
    $this->actingAs($marketing)->get(route('mcp.admin'))->assertForbidden();
    $this->actingAs($admin)->get(route('mcp.admin'))->assertSuccessful();
});

it('el wizard crea una conexión y muestra el token UNA sola vez (sin persistir en claro)', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('openWizard')
        ->set('connName', 'ChatGPT Desktop')
        ->call('nextStep')            // paso 1 → 2
        ->set('scope', 'global')
        ->call('nextStep')            // paso 2 → 3
        ->call('createConnection')
        ->assertSet('step', 4)
        ->assertSet('freshClientName', 'ChatGPT Desktop')
        ->assertSeeText('ChatGPT Desktop');

    $client = McpClient::query()->where('name', 'ChatGPT Desktop')->first();
    expect($client)->not->toBeNull();
    expect($client->is_active)->toBeTrue();
    expect($client->institution_id)->toBeNull(); // global
    // En BD solo el hash (64 hex), jamás el token en claro.
    expect($client->token_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('al cerrar el wizard el token desaparece de memoria', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('openWizard')
        ->set('connName', 'tmp')
        ->call('nextStep')->call('nextStep')->call('createConnection')
        ->assertNotSet('freshToken', null)
        ->call('closeWizard')
        ->assertSet('freshToken', null)
        ->assertSet('showWizard', false);
});

it('rotar genera clave nueva e invalida la anterior (mismo McpClientManager)', function () {
    [, $user] = adminCtx();
    [$client, $original] = app(McpClientManager::class)->create('conn-rot');
    $oldHash = $client->token_hash;

    Livewire::actingAs($user)->test(Admin::class)
        ->call('askRotate', $client->id)
        ->assertSet('confirmRotateId', $client->id)
        ->call('rotate')
        ->assertSet('step', 4);

    $client->refresh();
    expect($client->token_hash)->not->toBe($oldHash);
    // El token anterior ya no autentica: su hash no coincide con el guardado.
    expect(hash('sha256', $original))->not->toBe($client->token_hash);
});

it('revocar desactiva el cliente de inmediato y queda auditado', function () {
    [, $user] = adminCtx();
    [$client] = app(McpClientManager::class)->create('conn-rev');

    Livewire::actingAs($user)->test(Admin::class)
        ->call('askRevoke', $client->id)
        ->call('revoke');

    expect($client->refresh()->is_active)->toBeFalse();
    expect(McpAuditLog::query()->where('tool', 'mcp.client.revoke')->where('mcp_client_id', $client->id)->exists())->toBeTrue();
});

it('probar servidor reporta operativo con herramientas y clientes activos', function () {
    [, $user] = adminCtx();
    app(McpClientManager::class)->create('activo');

    Livewire::actingAs($user)->test(Admin::class)
        ->call('testServer')
        ->assertSet('testResult.ok', true)
        ->assertSet('testResult.db', true);
});

it('un admin de institución (no super) solo crea conexiones para SU institución', function () {
    [$inst, $user] = adminCtx(super: false);

    Livewire::actingAs($user)->test(Admin::class)
        ->assertSet('scope', 'institution')
        ->call('openWizard')
        ->set('connName', 'conn-inst')
        ->call('nextStep')->call('nextStep')->call('createConnection');

    $client = McpClient::query()->where('name', 'conn-inst')->first();
    expect($client->institution_id)->toBe($inst->id);
});

it('el listado y la actividad no exponen el hash del token', function () {
    [, $user] = adminCtx();
    [$client] = app(McpClientManager::class)->create('conn-vis');

    $html = Livewire::actingAs($user)->test(Admin::class)
        ->call('setTab', 'clients')
        ->html();

    expect($html)->toContain('conn-vis');
    expect($html)->not->toContain($client->token_hash);
});

it('descargar configuración entrega el archivo TOML (con placeholder, sin token persistido)', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('downloadConfig')
        ->assertFileDownloaded('mca-crm-mcp.toml');

    // El contenido (concat simple) se valida capturando el StreamedResponse directamente.
    $component = new Admin;
    $component->mount();
    $content = capture_streamed(fn () => $component->downloadConfig());
    expect($content)->toContain('[mcp_servers.mca-crm]');
    expect($content)->toContain('/api/mcp');
    expect($content)->toContain('<MCP_TOKEN>'); // placeholder, nunca un token real
});

function capture_streamed(callable $call): string
{
    $response = $call();
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('el comando mcp:client sigue funcionando con el servicio compartido', function () {
    adminCtx();

    $this->artisan('mcp:client', ['action' => 'create', 'name' => 'cli-conn'])
        ->assertSuccessful();

    expect(McpClient::query()->where('name', 'cli-conn')->exists())->toBeTrue();
});
