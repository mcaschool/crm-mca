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
 * Pantalla "Conexiones IA" (IA / MCP): presets ChatGPT/Claude/Claude Code,
 * wizard de Claude Code (Bearer, técnico, escritura OFF), instaladores,
 * pestaña Conexiones y permisos. ChatGPT/Claude NO crean conexión todavía
 * (OAuth pendiente). Reutiliza McpClientManager.
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

it('solo un administrador puede abrir la pantalla IA / MCP', function () {
    [$inst, $admin] = adminCtx();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    $this->actingAs($marketing)->get(route('mcp.admin'))->assertForbidden();
    $this->actingAs($admin)->get(route('mcp.admin'))->assertSuccessful()->assertSee('Conexiones IA');
});

it('el preset de Claude Code crea una conexión técnica con escritura DESACTIVADA (Bearer, token una vez)', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('connectClaudeCode')
        ->set('connName', 'Claude Code Oficina')
        ->call('nextStep')->call('nextStep')->call('createConnection')
        ->assertSet('step', 4)
        ->assertSeeText('Claude Code Oficina');

    $client = McpClient::query()->where('name', 'Claude Code Oficina')->first();
    expect($client->assistant_type)->toBe('claude_code');
    expect($client->profile)->toBe('technical');
    expect($client->allow_write)->toBeFalse();       // escritura OFF por defecto
    expect($client->auth_kind)->toBe('bearer');
    expect($client->token_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('ChatGPT y Claude NO crean conexión (solo informan; nada de simular conectado)', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('showPreset', 'chatgpt')
        ->assertSet('showInfo', 'chatgpt')
        ->assertSeeText('Servicio preparado')
        ->call('showPreset', 'claude')
        ->assertSet('showInfo', 'claude');

    expect(McpClient::query()->whereIn('assistant_type', ['chatgpt', 'claude'])->count())->toBe(0);
});

it('el token desaparece de memoria al cerrar el wizard', function () {
    [, $user] = adminCtx();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('connectClaudeCode')->set('connName', 'tmp')
        ->call('nextStep')->call('nextStep')->call('createConnection')
        ->assertNotSet('freshToken', null)
        ->call('closeWizard')
        ->assertSet('freshToken', null)
        ->assertSet('showWizard', false);
});

it('administrar permisos activa/desactiva las acciones técnicas de escritura', function () {
    [, $user] = adminCtx();
    [$client] = app(McpClientManager::class)->create('cc', null, 'claude_code', 'technical', false, 'bearer');

    Livewire::actingAs($user)->test(Admin::class)
        ->call('togglePermissions', $client->id);
    expect($client->refresh()->allow_write)->toBeTrue();

    Livewire::actingAs($user)->test(Admin::class)
        ->call('togglePermissions', $client->id);
    expect($client->refresh()->allow_write)->toBeFalse();
});

it('rotar genera clave nueva e invalida la anterior; revocar desconecta y audita', function () {
    [, $user] = adminCtx();
    [$client, $original] = app(McpClientManager::class)->create('cc2', null, 'claude_code', 'technical', false, 'bearer');
    $oldHash = $client->token_hash;

    Livewire::actingAs($user)->test(Admin::class)->call('askRotate', $client->id)->call('rotate')->assertSet('step', 4);
    $client->refresh();
    expect($client->token_hash)->not->toBe($oldHash);
    expect(hash('sha256', $original))->not->toBe($client->token_hash);

    Livewire::actingAs($user)->test(Admin::class)->call('askRevoke', $client->id)->call('revoke');
    expect($client->refresh()->is_active)->toBeFalse();
    expect(McpAuditLog::query()->where('tool', 'mcp.client.revoke')->where('mcp_client_id', $client->id)->exists())->toBeTrue();
});

it('los instaladores piden la clave localmente y NO contienen el token real', function () {
    [, $user] = adminCtx();

    // Crea la conexión (token en memoria) y descarga ambos instaladores.
    $component = Livewire::actingAs($user)->test(Admin::class)
        ->call('connectClaudeCode')->set('connName', 'cc-inst')
        ->call('nextStep')->call('nextStep')->call('createConnection');

    $token = $component->get('freshToken');
    $component->call('downloadInstaller', 'windows')->assertFileDownloaded('conectar-mca-crm.ps1');
    $component->call('downloadInstaller', 'unix')->assertFileDownloaded('conectar-mca-crm.sh');

    // Con el token AÚN en memoria, el contenido descargado NO debe incluirlo.
    $admin = new Admin;
    $admin->mount();
    $admin->freshToken = $token;
    $win = capture_streamed(fn () => $admin->downloadInstaller('windows'));
    $unix = capture_streamed(fn () => $admin->downloadInstaller('unix'));

    expect($win)->not->toContain($token);   // Windows: sin el token real
    expect($unix)->not->toContain($token);  // macOS/Linux: sin el token real
    expect($win)->toContain('Read-Host -AsSecureString'); // pide la clave en local
    expect($unix)->toContain('read -s');
    expect($win)->toContain('claude mcp add mca-crm --scope user');   // scope USER
    expect($unix)->toContain('claude mcp add mca-crm --scope user');
    expect($win)->toContain('claude mcp get mca-crm');   // verificación inmediata
    expect($unix)->toContain('claude mcp get mca-crm');
    expect($unix)->toContain('/api/mcp');   // el endpoint (no secreto) sí va
});

it('el estado de una conexión pasa de Pendiente a Conectado y a Desconectado', function () {
    [, $user] = adminCtx();
    [$client] = app(McpClientManager::class)->create('estado', null, 'claude_code', 'technical', false, 'bearer');

    // Recién creada: sin actividad MCP → Pendiente de conexión.
    Livewire::actingAs($user)->test(Admin::class)
        ->call('setTab', 'connections')
        ->assertSee('Pendiente de conexión')
        ->assertDontSee('Conectado');

    // Tras una llamada MCP autenticada (VerifyMcpToken fija last_used_at) → Conectado.
    $client->forceFill(['last_used_at' => now()])->save();
    Livewire::actingAs($user)->test(Admin::class)
        ->call('setTab', 'connections')
        ->assertSee('Conectado')
        ->assertDontSee('Pendiente de conexión');

    // Revocada → Desconectado.
    $client->forceFill(['is_active' => false])->save();
    Livewire::actingAs($user)->test(Admin::class)
        ->call('setTab', 'connections')
        ->assertSee('Desconectado');
});

it('la tarjeta Claude Code solo figura Conectado tras una llamada MCP exitosa', function () {
    [, $user] = adminCtx();
    [$client] = app(McpClientManager::class)->create('cc-card', null, 'claude_code', 'technical', false, 'bearer');

    // Con credencial pero sin actividad: NO conectado.
    Livewire::actingAs($user)->test(Admin::class)->assertSee('No conectado');

    // Con actividad registrada: Conectado.
    $client->forceFill(['last_used_at' => now()])->save();
    Livewire::actingAs($user)->test(Admin::class)->assertSee('Conectado');
});

it('un admin de institución (no super) solo conecta para SU institución', function () {
    [$inst, $user] = adminCtx(super: false);

    Livewire::actingAs($user)->test(Admin::class)
        ->call('connectClaudeCode')->set('connName', 'cc-inst2')
        ->call('nextStep')->call('nextStep')->call('createConnection');

    expect(McpClient::query()->where('name', 'cc-inst2')->value('institution_id'))->toBe($inst->id);
});

it('el comando mcp:client sigue funcionando con el servicio compartido', function () {
    adminCtx();
    $this->artisan('mcp:client', ['action' => 'create', 'name' => 'cli-conn'])->assertSuccessful();
    expect(McpClient::query()->where('name', 'cli-conn')->exists())->toBeTrue();
});

function capture_streamed(callable $call): string
{
    $response = $call();
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}
