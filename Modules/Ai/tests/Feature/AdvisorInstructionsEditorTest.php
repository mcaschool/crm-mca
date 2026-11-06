<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
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

// ── Fix 1: el aviso de «cambios sin guardar» solo se limpia tras un guardado EXITOSO

it('emite «advisor-saved» al guardar con éxito, pero NO cuando la validación falla', function () {
    [$bot, $admin] = aieSetup();

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', 'Instrucciones válidas.')
        ->call('save')->assertHasNoErrors()->assertDispatched('advisor-saved');

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('name', '')                                   // nombre requerido → falla la validación
        ->set('instructions', 'Texto que no debe perderse.')
        ->call('save')->assertHasErrors(['name'])
        ->assertNotDispatched('advisor-saved')              // el aviso permanece activo
        ->assertSet('instructions', 'Texto que no debe perderse.');
});

// ── Fix 2: coherencia contador (puntos de código) ↔ validación Laravel (mb_strlen), con emojis/acentos/saltos

it('el límite se cuenta por puntos de código (como mb_strlen): emojis y acentos al límite pasan, +1 falla, y se conservan', function () {
    [$bot, $admin] = aieSetup();

    $emojis = str_repeat('😀', 50000);                       // 50.000 puntos de código (mb_strlen) = 50.000
    expect(mb_strlen($emojis))->toBe(50000);
    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', $emojis)->call('save')->assertHasNoErrors();
    expect(mb_strlen((string) $bot->fresh()->instructions))->toBe(50000)
        ->and($bot->fresh()->instructions)->toBe($emojis);  // se conservan íntegros

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('instructions', str_repeat('😀', 50001))->call('save')->assertHasErrors(['instructions']);

    // Acentos y saltos de línea también cuentan por punto de código (1 cada uno).
    $acentos = str_repeat('é', 20000);
    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('restrictions', $acentos)->call('save')->assertHasNoErrors();
    expect(mb_strlen((string) $bot->fresh()->restrictions))->toBe(20000);
});

// ── Fix 3: la reversión de la migración no trunca datos existentes mayores que TEXT

it('el down() de la migración ABORTA (no trunca) si hay textos mayores que la capacidad de TEXT', function () {
    [$bot] = aieSetup();
    $big = str_repeat('á', 40000);                           // 40.000 × 2 bytes = 80.000 > 65.535 (TEXT)
    DB::table('bots')->where('id', $bot->id)->update(['instructions' => $big]);

    $migration = require base_path('Modules/Institutions/database/migrations/2026_11_06_100000_widen_bot_instruction_fields.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    // No se tocó la columna ni el dato: sigue íntegro (sin truncar).
    expect(DB::table('bots')->where('id', $bot->id)->value('instructions'))->toBe($big);
});

// ── Fix 4: el formulario real renderiza el editor ampliado y el contador; el textarea no fija maxlength

it('el formulario real renderiza «Ampliar editor», el contador del límite y el textarea sin maxlength', function () {
    [$bot, $admin] = aieSetup();
    $html = Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])->html();

    expect($html)->toContain('Ampliar editor')->toContain('50,000')->toContain('ui-editor-modal');
    preg_match('/<textarea[^>]*id="adv-instructions"[^>]*>/', $html, $m);
    expect($m[0] ?? 'sin-match')->not->toContain('maxlength');
});
