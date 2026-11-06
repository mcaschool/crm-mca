<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * «Identidad e instrucciones» del asesor: edición y persistencia de textos largos (instrucciones,
 * límites, reglas de transferencia) con límites amplios, sin truncar y conservando el formato. No
 * toca el motor de IA ni la recuperación de conocimiento.
 */
function aieSetup(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->create(['institution_id' => $inst->id, 'assistant_name' => 'Sophia', 'type' => 'ia']);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    return [$bot, $admin, $inst];
}

it('guarda y recupera instrucciones largas (>6000) conservando saltos, numeración e indentación, sin truncar', function () {
    [$bot, $admin] = aieSetup();
    $long = rtrim("Rol del asesor Sophia.\n\n1. Atiende Diplomas Avanzados.\n2. Prioriza:\n   a. Identificar la línea.\n   b. Responder con datos.\n\n"
        .str_repeat('Párrafo de ejemplo con suficiente detalle para superar el límite anterior. ', 120));
    expect(strlen($long))->toBeGreaterThan(6000);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', $long)
        ->call('save')->assertHasNoErrors();

    expect($bot->fresh()->instructions)->toBe($long);   // idéntico: \n, numeración e indentación
});

it('tope de almacenamiento amplio: 50.000 no trunca; 50.001 marca error sin perder el texto; 20.000 en límites y transferencia', function () {
    [$bot, $admin] = aieSetup();

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', str_repeat('a', 50000))->call('save')->assertHasNoErrors();
    expect(strlen((string) $bot->fresh()->instructions))->toBe(50000);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', str_repeat('a', 50001))->call('save')
        ->assertHasErrors(['instructions'])
        ->assertSet('instructions', str_repeat('a', 50001));   // el texto NO desaparece al fallar

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('restrictions', str_repeat('b', 20001))
        ->set('handoffRules', str_repeat('c', 20001))
        ->call('save')->assertHasErrors(['restrictions', 'handoffRules']);
});

it('no colapsa los espacios internos; solo normaliza CRLF a LF y recorta los extremos', function () {
    [$bot, $admin] = aieSetup();

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', "  línea con   espacios\r\n\ttabulada\r\n")
        ->call('save')->assertHasNoErrors();

    expect($bot->fresh()->instructions)->toBe("línea con   espacios\n\ttabulada");
});

it('los datos actuales de un asesor existente se cargan en el editor sin alterarse', function () {
    [$bot, $admin] = aieSetup();
    $bot->forceFill(['instructions' => "1. Regla A\n2. Regla B"])->save();

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSet('instructions', "1. Regla A\n2. Regla B");
});

it('solo un administrador puede abrir la configuración del asesor', function () {
    [$bot, , $inst] = aieSetup();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    Livewire::actingAs($marketing)->test(Form::class, ['bot' => $bot])->assertForbidden();
});
