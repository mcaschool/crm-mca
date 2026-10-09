<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Livewire\Advisor\Preview;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * «{asesor} está escribiendo…» en la capa común (widget y «Probar asesor»): el nombre sale SIEMPRE
 * del asesor que responde (nunca «Celia» fijo) y la espera mínima es configurable por asesor (0–8 s,
 * 0 = sin espera). La espera ocurre en el navegador: el servidor solo entrega el dato.
 *
 * @return array{0: Institution, 1: User}
 */
function typCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

/** @return array<string, mixed> */
function typSession(Bot $bot): array
{
    return test()->withHeaders(['X-Bot-Key' => $bot->public_key])->postJson('/api/v1/widget/session', [])->assertOk()->json('bot');
}

it('el widget recibe el nombre y la espera del asesor que responde: Sophia, Celia y uno nuevo creado desde la ficha', function () {
    [, $admin] = typCtx();
    $sophia = Bot::factory()->create(['assistant_name' => 'Sophia', 'typing_delay' => 5]);
    $celia = Bot::factory()->create(['assistant_name' => 'Celia']);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Mateo')->set('typingDelay', '2')
        ->call('save')->assertHasNoErrors();
    $mateo = Bot::query()->where('assistant_name', 'Mateo')->firstOrFail();
    $mateo->forceFill(['status' => 'active'])->save();

    expect(typSession($sophia))->toMatchArray(['assistant_name' => 'Sophia', 'typing_delay' => 5])
        ->and(typSession($celia))->toMatchArray(['assistant_name' => 'Celia', 'typing_delay' => 3])   // 3 s por defecto
        ->and(typSession($mateo))->toMatchArray(['assistant_name' => 'Mateo', 'typing_delay' => 2]);
});

it('el script del widget compone «{nombre} está escribiendo…» con el asesor de la sesión, sin nombre fijo', function () {
    $res = test()->get('/widget/chat-widget.js')->assertOk();
    $js = (string) file_get_contents($res->baseResponse->getFile()->getPathname());

    expect($js)->toContain("typingName: '{name} está escribiendo…'")
        ->and($js)->toContain("typingName: '{name} is typing…'")
        ->and($js)->toContain("t('typingName').replace('{name}', state.assistant)")
        ->and($js)->toContain('atLeast(api(\'/celia\'')                 // espera mínima en el navegador
        ->and($js)->toContain('state.typingDelay')
        ->and($js)->toContain('.typing-named')                           // también sin animación con movimiento reducido
        ->and($js)->not->toContain('Celia está escribiendo');
});

it('«Probar asesor» muestra «{nombre} está escribiendo…» en el idioma de la conversación y su espera mínima', function () {
    typCtx();
    $sophia = Bot::factory()->create(['assistant_name' => 'Sophia', 'status' => 'inactive', 'default_language' => 'es', 'typing_delay' => 4]);
    $token = app(AdvisorPreviewLinkService::class)->generate($sophia);

    Livewire::test(Preview::class, ['token' => $token])
        ->assertSee('Sophia está escribiendo…')
        ->assertSeeHtml('data-typing-delay="4000"')
        ->call('setLang', 'en')
        ->assertSee('Sophia is typing…')
        ->assertDontSee('Celia is typing');
});

it('0 desactiva la espera mínima y la ficha solo acepta de 0 a 8 segundos', function () {
    [, $admin] = typCtx();
    $bot = Bot::factory()->create(['assistant_name' => 'Celia']);

    foreach (['9' => 'max', '-1' => 'min', '' => 'required', 'dos' => 'integer'] as $value => $rule) {
        Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
            ->set('typingDelay', (string) $value)->call('save')->assertHasErrors(['typingDelay' => $rule]);
    }
    expect($bot->fresh()->typing_delay)->toBe(3);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSet('typingDelay', '3')
        ->set('typingDelay', '0')->call('save')->assertHasNoErrors();
    expect($bot->fresh()->typing_delay)->toBe(0)->and(typSession($bot)['typing_delay'])->toBe(0);

    $token = app(AdvisorPreviewLinkService::class)->generate($bot->fresh());
    Livewire::test(Preview::class, ['token' => $token])->assertSeeHtml('data-typing-delay="0"');

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])->set('typingDelay', '8')->call('save')->assertHasNoErrors();
    expect($bot->fresh()->typing_delay)->toBe(8);

    // Un valor fuera de rango en la base de datos nunca llega al navegador sin acotar.
    $bot->forceFill(['typing_delay' => 30])->save();
    expect($bot->fresh()->typingDelay())->toBe(8);
});

it('aislamiento: cada institución ve el nombre y la espera de SU asesor', function () {
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $ctx = app(CurrentInstitution::class);
    $botA = $ctx->runFor($a->id, fn () => Bot::factory()->create(['assistant_name' => 'Celia', 'typing_delay' => 1]));
    $botB = $ctx->runFor($b->id, fn () => Bot::factory()->create(['assistant_name' => 'Lucía', 'typing_delay' => 6]));

    expect(typSession($botA))->toMatchArray(['assistant_name' => 'Celia', 'typing_delay' => 1])
        ->and(typSession($botB))->toMatchArray(['assistant_name' => 'Lucía', 'typing_delay' => 6]);

    // La ficha de una institución no puede tocar la espera del asesor de otra.
    $adminA = User::factory()->create(['institution_id' => $a->id, 'role' => 'admin']);
    $ctx->set($a->id);
    expect(fn () => Livewire::actingAs($adminA)->test(Form::class, ['bot' => $botB->getKey()]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($ctx->runFor($b->id, fn () => Bot::query()->findOrFail($botB->id)->typing_delay))->toBe(6);
});
