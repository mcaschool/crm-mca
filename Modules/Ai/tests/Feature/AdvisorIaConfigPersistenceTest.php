<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Regresión: "Configuración de IA" del asesor (proveedor + modelo) debe persistir y
 * volver a mostrarse tras recargar. El bug era que el <select> de proveedor usaba
 * wire:model DIFERIDO y su valor no llegaba al guardar; como la escritura en
 * ai_process_configs está tras `if (integrationId && model)`, no guardaba ninguno.
 * El arreglo (wire:model.live en el select + .blur en el modelo) sincroniza el valor
 * de forma fiable. Aquí se bloquea el ciclo guardar → recargar a nivel de servidor.
 */
function iaCfgSetup(): array
{
    $inst = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->create(['assistant_name' => 'Celia', 'type' => 'ia', 'status' => 'active']);
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);

    return [$admin, $bot, $integration];
}

it('guarda proveedor+modelo y PERSISTEN tras recargar (round-trip)', function () {
    [$admin, $bot, $integration] = iaCfgSetup();

    // Guardar desde el formulario (edición).
    Livewire::actingAs($admin)
        ->test(Form::class, ['bot' => $bot])
        ->set('integrationId', $integration->id)
        ->set('model', 'qwen3.7-plus')
        ->call('save')
        ->assertHasNoErrors();

    // Persistió en ai_process_configs (proceso de conversación).
    $cfg = AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->first();
    expect($cfg)->not->toBeNull();
    expect($cfg->integration_id)->toBe($integration->id);
    expect($cfg->model)->toBe('qwen3.7-plus');

    // Recarga = mount nuevo: repuebla proveedor y modelo (antes volvían a vacío).
    Livewire::actingAs($admin)
        ->test(Form::class, ['bot' => $bot])
        ->assertSet('integrationId', $integration->id)
        ->assertSet('model', 'qwen3.7-plus');
});

it('editar y cambiar el proveedor/modelo actualiza la fila existente', function () {
    [$admin, $bot, $integration] = iaCfgSetup();
    $other = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'openai', 'status' => 'active']);

    // Fila previa.
    AiProcessConfig::create([
        'bot_id' => $bot->id, 'process' => 'conversation',
        'integration_id' => $integration->id, 'model' => 'qwen3.7-plus', 'status' => 'active',
    ]);

    Livewire::actingAs($admin)
        ->test(Form::class, ['bot' => $bot])
        ->assertSet('integrationId', $integration->id)   // carga la previa
        ->set('integrationId', $other->id)
        ->set('model', 'gpt-4o-mini')
        ->call('save')
        ->assertHasNoErrors();

    // Una sola fila, actualizada (no duplica).
    expect(AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->count())->toBe(1);
    $cfg = AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->first();
    expect($cfg->integration_id)->toBe($other->id);
    expect($cfg->model)->toBe('gpt-4o-mini');
});
