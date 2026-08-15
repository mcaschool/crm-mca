<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Livewire\Integrations\Configure;
use Modules\Integrations\Models\Integration;

/**
 * Almacen de credenciales: cifrado y las 7 barreras (el secreto nunca se
 * devuelve completo tras guardarse).
 */
function adminWithContext(): User
{
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    return $admin;
}

const SECRET = 'sk-secreto-larguisimo-ABCDEF3456';

it('guarda la credencial cifrada y enmascarada (no en claro)', function () {
    adminWithContext();

    Livewire::test(Configure::class, ['type' => 'ai_provider'])
        ->set('provider', 'openai')
        ->set('inputs.api_key', SECRET)
        ->set('inputs.base_url', 'https://api.openai.com/v1')
        ->call('save')
        ->assertRedirect(route('integrations.index'));

    $integration = Integration::query()->where('type', 'ai_provider')->first();
    expect($integration)->not->toBeNull();
    expect($integration->secret('api_key'))->toBe(SECRET);
    expect($integration->provider)->toBe('openai');

    // En disco NO esta el secreto en claro (columna cifrada).
    $raw = DB::table('integrations')->where('id', $integration->id)->value('config');
    expect($raw)->not->toContain(SECRET);

    // El preview (unica version "exportable") va enmascarado.
    expect($integration->maskedConfig()['api_key'])->toBe('sk-••••3456');
    // El metadato no secreto se ve en claro.
    expect($integration->maskedConfig()['base_url'])->toBe('https://api.openai.com/v1');
});

it('al editar, el input del secreto NO se prellena y no se muestra completo', function () {
    adminWithContext();
    Integration::factory()->withSecrets(['api_key' => SECRET])->create([
        'type' => 'ai_provider', 'provider' => 'openai', 'name' => 'OpenAI',
    ]);

    $component = Livewire::test(Configure::class, ['type' => 'ai_provider']);

    // El input del secreto esta vacio (no se rehidrata con el valor real).
    expect($component->get('inputs')['api_key'] ?? '')->toBe('');
    // La vista nunca muestra el secreto completo.
    $component->assertDontSee(SECRET);
});

it('reemplaza la credencial y re-cifra; el enmascarado se actualiza', function () {
    adminWithContext();
    Integration::factory()->withSecrets(['api_key' => SECRET])->create([
        'type' => 'ai_provider', 'provider' => 'openai', 'name' => 'OpenAI',
    ]);

    $new = 'sk-nueva-credencial-XYZ9876';
    Livewire::test(Configure::class, ['type' => 'ai_provider'])
        ->set('inputs.api_key', $new)
        ->call('save');

    $integration = Integration::query()->where('type', 'ai_provider')->first();
    expect($integration->secret('api_key'))->toBe($new);
    expect($integration->maskedConfig()['api_key'])->toBe('sk-••••9876');
});

it('guardar con el secreto vacio CONSERVA la credencial actual', function () {
    adminWithContext();
    Integration::factory()->withSecrets(['api_key' => SECRET])->create([
        'type' => 'ai_provider', 'provider' => 'openai', 'name' => 'OpenAI',
    ]);

    Livewire::test(Configure::class, ['type' => 'ai_provider'])
        ->set('inputs.api_key', '')
        ->call('save');

    $integration = Integration::query()->where('type', 'ai_provider')->first();
    expect($integration->secret('api_key'))->toBe(SECRET);
});

it('no retiene el secreto recien tecleado en el estado ni en el snapshot tras guardar', function () {
    adminWithContext();
    $secret = 'sk-en-transito-SECRETO-98765';

    $component = Livewire::test(Configure::class, ['type' => 'ai_provider'])
        ->set('provider', 'openai')
        ->set('inputs.api_key', $secret)
        ->call('save');

    // (a) la propiedad del secreto queda vacia tras persistir.
    $component->assertSet('inputs.api_key', '');

    // (b) el snapshot que Livewire serializa hacia el navegador no lleva el claro.
    expect(json_encode($component->getData()))->not->toContain($secret);

    // Y se guardo cifrado (el secreto ya guardado si es recuperable en servidor).
    $integration = Integration::query()->where('type', 'ai_provider')->first();
    expect($integration->secret('api_key'))->toBe($secret);
});

it('ninguna pantalla del panel devuelve el secreto completo', function () {
    adminWithContext();
    Integration::factory()->withSecrets(['api_key' => SECRET])->create([
        'type' => 'ai_provider', 'provider' => 'openai', 'name' => 'OpenAI',
    ]);

    // Indice de integraciones.
    test()->get('/integrations')->assertOk()->assertDontSee(SECRET);
    // Pagina de configuracion.
    test()->get('/integrations/ai_provider/configure')->assertOk()->assertDontSee(SECRET);
});
