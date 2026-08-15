<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Livewire\Integrations\Index;
use Modules\Integrations\Models\Integration;
use Modules\Integrations\Services\ConnectionTester;
use Modules\Integrations\Services\ConnectionTestResult;

/**
 * Prueba de conexion por tipo (real para IA/SMTP/n8n; el resto, pendiente).
 * Se usa HTTP fake: no se hacen llamadas reales.
 */
function testerContext(): void
{
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);
}

it('valida la credencial de un proveedor de IA con clave correcta', function () {
    testerContext();
    Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

    $integration = Integration::factory()->withSecrets(['api_key' => 'sk-buena-clave'])->create([
        'type' => 'ai_provider', 'provider' => 'openai',
    ]);

    $result = app(ConnectionTester::class)->test($integration);

    expect($result)->toBeInstanceOf(ConnectionTestResult::class);
    expect($result->ok)->toBeTrue();
});

it('marca fallo con clave invalida y NO filtra el secreto en el mensaje', function () {
    testerContext();
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

    $integration = Integration::factory()->withSecrets(['api_key' => 'sk-clave-secreta-999'])->create([
        'type' => 'ai_provider', 'provider' => 'openai',
    ]);

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeFalse();
    // Barrera: el mensaje (que se guarda y se muestra) nunca contiene el secreto.
    expect($result->message)->not->toContain('sk-clave-secreta-999');
});

it('los tipos sin prueba real quedan como pendientes', function () {
    testerContext();
    $integration = Integration::factory()->withSecrets(['secret_key' => 'sk_live_x'])->create([
        'type' => 'stripe', 'provider' => null,
    ]);

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->pending)->toBeTrue();
});

it('el boton Probar del panel persiste el resultado sin exponer el secreto', function () {
    testerContext();
    Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

    $integration = Integration::factory()->withSecrets(['api_key' => 'sk-otra-clave-secreta'])->create([
        'type' => 'ai_provider', 'provider' => 'openai',
    ]);

    Livewire::test(Index::class)
        ->call('test', $integration->id)
        ->assertDontSee('sk-otra-clave-secreta');

    $integration->refresh();
    expect($integration->last_test_ok)->toBeTrue();
    expect($integration->last_tested_at)->not->toBeNull();
    expect((string) $integration->last_test_message)->not->toContain('sk-otra-clave-secreta');
});
