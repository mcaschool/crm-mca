<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * «Presentación del widget» por asesor: mensaje de bienvenida y texto del botón (ES/EN),
 * editables en la ficha, por institución y asesor, con los textos de siempre por defecto.
 *
 * @return array{0: Institution, 1: User}
 */
function advTextsCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

function advTextsConfig(Bot $bot): array
{
    return test()->withHeaders(['X-Bot-Key' => $bot->public_key])->getJson('/api/v1/widget/config')->assertOk()->json();
}

it('guarda y actualiza los dos textos por asesor desde la ficha (crear y editar)', function () {
    [, $admin] = advTextsCtx();

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Sofía')
        ->set('welcomeEs', 'Hola 👋 Soy Sofía. ¿Te ayudo con tu Diploma Avanzado?')
        ->set('buttonEs', '¡Hablemos!')
        ->set('buttonEn', "Let's chat!")
        ->call('save')->assertHasNoErrors();

    $bot = Bot::query()->where('assistant_name', 'Sofía')->firstOrFail();
    expect($bot->only(['widget_welcome_es', 'widget_welcome_en', 'widget_button_es', 'widget_button_en']))->toBe([
        'widget_welcome_es' => 'Hola 👋 Soy Sofía. ¿Te ayudo con tu Diploma Avanzado?',
        'widget_welcome_en' => null,
        'widget_button_es' => '¡Hablemos!',
        'widget_button_en' => "Let's chat!",
    ]);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSet('buttonEs', '¡Hablemos!')
        ->set('buttonEs', '  ¡Conversemos   ahora!  ')
        ->set('welcomeEs', '')                      // vacío → vuelve al texto por defecto
        ->call('save')->assertHasNoErrors();

    expect($bot->fresh()->widget_button_es)->toBe('¡Conversemos ahora!')
        ->and($bot->fresh()->widget_welcome_es)->toBeNull();
});

it('un asesor sin textos propios conserva exactamente los del widget actual', function () {
    advTextsCtx();
    $bot = Bot::factory()->create(['status' => 'active']);

    expect(advTextsConfig($bot)['texts'])->toBe([
        'es' => ['welcome' => 'Hola 👋 Soy Celia. ¿Te ayudo a elegir tu microcredencial?', 'button' => '¡Conversemos!'],
        'en' => ['welcome' => "Hi 👋 I'm Celia. Shall I help you choose your microcredential?", 'button' => "Let's talk!"],
    ]);

    // Los valores por defecto del servidor son los mismos que trae chat-widget.js (sin cambio visual).
    $js = (string) file_get_contents(resource_path('widget/chat-widget.js'));
    foreach (['es', 'en'] as $lang) {
        expect($js)->toContain((string) config("crm.widget.default_texts.{$lang}.welcome"))
            ->toContain((string) config("crm.widget.default_texts.{$lang}.button"));
    }
});

it('el widget recibe los textos del asesor de SU clave e institución', function () {
    [$instA] = advTextsCtx();
    $botA = Bot::factory()->create(['status' => 'active', 'widget_welcome_es' => 'Bienvenida A', 'widget_button_es' => 'Botón A']);
    $instB = Institution::factory()->create();
    $botB = app(CurrentInstitution::class)->runFor($instB->id, fn () => Bot::factory()->create(['status' => 'active', 'widget_welcome_es' => 'Bienvenida B']));
    app(CurrentInstitution::class)->set($instA->id);

    expect(advTextsConfig($botA)['texts']['es'])->toBe(['welcome' => 'Bienvenida A', 'button' => 'Botón A'])
        ->and(advTextsConfig($botB)['texts']['es'])->toBe(['welcome' => 'Bienvenida B', 'button' => '¡Conversemos!']);

    // Desde la institución A no se puede abrir (ni editar) la ficha del asesor de B.
    $admin = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin']);
    test()->actingAs($admin)->get(route('advisors.edit', $botB->id))->assertNotFound();
});

it('rechaza HTML/scripts y acepta texto Unicode con emojis; la salida va siempre escapada', function () {
    [, $admin] = advTextsCtx();
    $bot = Bot::factory()->create(['status' => 'active']);

    foreach (['<script>alert(1)</script>', 'Hola <b>Celia</b>', '<img src=x onerror=alert(1)>'] as $evil) {
        Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
            ->set('welcomeEs', $evil)->call('save')->assertHasErrors(['welcomeEs' => 'not_regex']);
        Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
            ->set('buttonEn', $evil)->call('save')->assertHasErrors(['buttonEn']);
    }
    expect($bot->fresh()->widget_welcome_es)->toBeNull();

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('welcomeEs', 'Tom & "Jerry" > 5 😀 ñ')->call('save')->assertHasNoErrors();
    expect($bot->fresh()->widget_welcome_es)->toBe('Tom & "Jerry" > 5 😀 ñ')
        ->and(advTextsConfig($bot)['texts']['es']['welcome'])->toBe('Tom & "Jerry" > 5 😀 ñ'); // JSON: el widget lo pinta como texto

    // Longitud razonable.
    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->set('buttonEs', str_repeat('a', 41))->call('save')->assertHasErrors(['buttonEs' => 'max'])
        ->set('buttonEs', 'ok')->set('welcomeEs', str_repeat('b', 201))->call('save')->assertHasErrors(['welcomeEs' => 'max']);

    // El widget asigna los textos como TEXTO (textContent / esc), nunca como HTML.
    $js = (string) file_get_contents(resource_path('widget/chat-widget.js'));
    expect($js)->toContain("tt.textContent = t('teaser')")->toContain("lm.textContent = t('launcher')");
});

it('regresión: /config no crea conversaciones ni eventos y la sesión del widget sigue igual', function () {
    advTextsCtx();
    $bot = Bot::factory()->create(['status' => 'active', 'public_key' => str_repeat('w', 32)]);

    $res = test()->withHeaders(['X-Bot-Key' => $bot->public_key])->getJson('/api/v1/widget/config')->assertOk();
    expect((string) $res->headers->get('Cache-Control'))->toContain('max-age=60')->toContain('private')->not->toContain('public')
        ->and((string) $res->headers->get('Vary'))->toContain('X-Bot-Key')                     // nunca en caché compartida
        ->and(app('router')->getRoutes()->getByName('api.widget.config')->gatherMiddleware())->toContain('throttle:widget-config')
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0);

    test()->withHeaders(['X-Bot-Key' => $bot->public_key])->postJson('/api/v1/widget/session', [])
        ->assertOk()->assertJsonPath('bot.assistant_name', $bot->assistant_name);
    expect(Conversation::query()->first())->channel->toBe('web')->is_test->toBeFalse();

    test()->withHeaders(['X-Bot-Key' => str_repeat('z', 32)])->getJson('/api/v1/widget/config')->assertNotFound();
});
