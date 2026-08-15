<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * Cifrado de secretos y sus barreras (Bloque 0, base para el Bloque 2).
 */
beforeEach(function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
});

it('guarda la credencial CIFRADA en la base de datos (no en claro)', function () {
    $integration = Integration::factory()->create();
    $integration->replaceSecrets(['api_key' => 'sk-super-secreto-1234']);
    $integration->save();

    // Lo que hay en disco NO contiene el secreto en claro.
    $raw = DB::table('integrations')->where('id', $integration->id)->value('config');
    expect($raw)->not->toContain('sk-super-secreto-1234');

    // Pero el modelo lo descifra al leerlo.
    expect($integration->fresh()->secret('api_key'))->toBe('sk-super-secreto-1234');
});

it('oculta config de toda serializacion (barrera de salida)', function () {
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-test-0123456789ABCDEF'])
        ->create();

    // La columna cifrada `config` nunca aparece en la serializacion.
    expect($integration->toArray())->not->toHaveKey('config');
    // El VALOR del secreto no se filtra (el nombre de la clave si puede estar en
    // config_preview, con valor enmascarado; eso es lo que el panel muestra).
    expect(json_encode($integration))->not->toContain('sk-test-0123456789ABCDEF');
});

it('expone solo la version enmascarada en config_preview', function () {
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-proj-AbCdEf1234567890-8Jk2', 'base_url' => 'https://api.openai.com/v1'])
        ->create();

    $preview = $integration->maskedConfig();

    expect($preview['api_key'])->toBe('sk-••••8Jk2');
    expect($preview['base_url'])->toBe('https://api.openai.com/v1');
    // El preview jamas contiene el secreto completo.
    expect(json_encode($preview))->not->toContain('AbCdEf');
});

it('config no es asignable en masa (barrera anti mass-assignment)', function () {
    $integration = Integration::factory()->create();

    $integration->fill(['config' => ['api_key' => 'inyectado']]);

    // fill() ignoro config por no estar en $fillable.
    expect($integration->secret('api_key'))->not->toBe('inyectado');
});

it('guardar con un valor vacio conserva la credencial actual', function () {
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-original-1234'])
        ->create();

    // Formulario "Reemplazar credencial" enviado en blanco.
    $integration->replaceSecrets(['api_key' => '']);
    $integration->save();

    expect($integration->fresh()->secret('api_key'))->toBe('sk-original-1234');
});
