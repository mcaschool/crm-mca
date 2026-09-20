<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Models\McpClient;

/**
 * Perfiles de acceso APLICADOS EN EL SERVIDOR (no solo en la UI): un cliente de
 * inspección no puede LISTAR ni EJECUTAR herramientas de escritura, aunque el
 * modelo lo intente. Usa el endpoint real /api/mcp (helpers mcpRpc/mcpTool de
 * McpServerTest).
 */

/** Crea un cliente con perfil/allow_write dados y devuelve su token en claro. */
function profileClient(string $profile, bool $allowWrite): string
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $token = 'mcp_'.str_repeat('p', 44).uniqid();
    McpClient::query()->create([
        'name' => 'prof-'.uniqid(),
        'assistant_type' => $profile === 'inspection' ? 'chatgpt' : 'claude_code',
        'profile' => $profile,
        'allow_write' => $allowWrite,
        'auth_kind' => 'bearer',
        'token_hash' => hash('sha256', $token),
        'institution_id' => null,
        'is_active' => true,
    ]);

    return $token;
}

it('una llamada MCP autenticada fija last_used_at (base del estado Conectado)', function () {
    $token = profileClient('inspection', false);
    $client = McpClient::query()->latest('id')->first();
    expect($client->last_used_at)->toBeNull(); // recién creado: pendiente

    mcpRpc($token, 'tools/list')->assertOk();

    expect($client->refresh()->last_used_at)->not->toBeNull(); // ya "conectado"
});

it('INSPECTION: tools/list oculta las herramientas de escritura', function () {
    $token = profileClient('inspection', false);

    $names = collect(mcpRpc($token, 'tools/list')->json('result.tools'))->pluck('name');

    expect($names)->toContain('crm_query', 'crm_overview', 'crm_code_read'); // lectura sí
    expect($names)->not->toContain('crm_record_create', 'crm_record_update', 'crm_record_delete', 'crm_execute');
});

it('INSPECTION: el servidor BLOQUEA la ejecución de escritura aunque se invoque directamente', function () {
    $token = profileClient('inspection', false);

    // Lectura permitida.
    [, $readErr] = mcpTool($token, 'crm_overview');
    expect($readErr)->toBeFalse();

    // Escritura denegada por el backend (no basta ocultarla).
    [, $isError, $raw] = mcpTool($token, 'crm_record_create', [
        'model' => 'Crm.Contact',
        'data' => ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.test'],
    ]);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('solo lectura');

    [, $execErr] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear', 'confirm' => true]);
    expect($execErr)->toBeTrue();
});

it('TECHNICAL sin allow_write: lectura completa pero escritura bloqueada', function () {
    $token = profileClient('technical', false);

    $names = collect(mcpRpc($token, 'tools/list')->json('result.tools'))->pluck('name');
    expect($names)->toContain('crm_code_search', 'crm_logs'); // inspección técnica
    expect($names)->not->toContain('crm_record_delete', 'crm_execute'); // escritura oculta

    [, $isError, $raw] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear', 'confirm' => true]);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('escritura'); // mensaje que orienta a activar permisos
});

it('un cliente LEGACY sin profile NO tiene escritura (perfil ausente ≠ escritura)', function () {
    // Cliente creado "a mano" como los legados: sin profile ni allow_write.
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $token = 'mcp_'.str_repeat('l', 44).uniqid();
    McpClient::query()->create([
        'name' => 'legacy-'.uniqid(),
        'token_hash' => hash('sha256', $token),
        'institution_id' => null,
        'is_active' => true,
    ]);

    // Inspecciona (lectura) pero NO ejecuta escritura.
    [, $readErr] = mcpTool($token, 'crm_overview');
    expect($readErr)->toBeFalse();
    [, $writeErr] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear', 'confirm' => true]);
    expect($writeErr)->toBeTrue();
});

it('mcp:client create NO habilita escritura por defecto; --allow-write sí', function () {
    Institution::factory()->create();

    test()->artisan('mcp:client', ['action' => 'create', 'name' => 'cli-ro'])->assertSuccessful();
    expect(McpClient::query()->where('name', 'cli-ro')->first()->allow_write)->toBeFalse();

    test()->artisan('mcp:client', ['action' => 'create', 'name' => 'cli-rw', '--allow-write' => true])->assertSuccessful();
    expect(McpClient::query()->where('name', 'cli-rw')->first()->allow_write)->toBeTrue();
});

it('TECHNICAL con allow_write: la escritura queda disponible (confirmaciones intactas)', function () {
    $token = profileClient('technical', true);

    $names = collect(mcpRpc($token, 'tools/list')->json('result.tools'))->pluck('name');
    expect($names)->toContain('crm_execute', 'crm_record_create');

    // La confirmación de seguridad sigue vigente: sin confirm, se rechaza.
    [, $needsConfirm, $raw] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear']);
    expect($needsConfirm)->toBeTrue();
    expect($raw)->toContain('confirm');

    // Con confirm, se ejecuta.
    [$ok, $isError] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear', 'confirm' => true]);
    expect($isError)->toBeFalse();
    expect($ok['result']['exit_code'])->toBe(0);
});
