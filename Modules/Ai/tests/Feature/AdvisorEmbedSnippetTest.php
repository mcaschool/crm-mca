<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * @return array{0: User, 1: Bot}
 */
function embedSetup(string $role = 'admin'): array
{
    $institution = Institution::factory()->create();
    $bot = app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create([
        'status' => 'active', 'assistant_name' => 'Celia', 'type' => 'ia',
        'public_key' => 'PUBKEYTEST1234567890ABCDEF',
    ]));
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);

    return [$user, $bot];
}

it('el Admin ve el snippet de incrustación con la public_key real y el dominio de producción', function () {
    [$admin, $bot] = embedSetup('admin');

    $this->actingAs($admin);

    Livewire::test(Form::class, ['bot' => $bot])
        ->assertOk()
        ->assertSee('Incrustar widget')
        ->assertSee('PUBKEYTEST1234567890ABCDEF')                       // public_key REAL del bot
        ->assertSee('https://crm.mcaschool.education')                  // dominio de producción (config default)
        ->assertSee('/widget/celia.js')
        ->assertSee('data-offset-bottom', false)                        // separación configurable
        ->assertSee('document.createElement', false);                  // variante JS puro (WordPress)
});

it('ofrece las dos variantes con la separación inferior configurada', function () {
    [$admin, $bot] = embedSetup('admin');
    config(['crm.widget_offset_bottom' => 90]);

    $this->actingAs($admin);

    Livewire::test(Form::class, ['bot' => $bot])
        ->assertSee('Opción 1')                        // etiqueta <script>
        ->assertSee('Opción 2')                        // JavaScript puro (WordPress)
        ->assertSee('data-offset-bottom', false)       // presente en ambas variantes
        ->assertSee('createElement', false)            // variante JS puro
        ->assertSee('appendChild', false)
        ->assertSee('Valor actual');                   // el help muestra el valor configurado
});

it('el snippet apunta al dominio configurado (env-overridable)', function () {
    [$admin, $bot] = embedSetup('admin');
    config(['crm.widget_embed_url' => 'https://otro-dominio.test']);

    $this->actingAs($admin);

    Livewire::test(Form::class, ['bot' => $bot])
        ->assertSee('https://otro-dominio.test/widget/celia.js')
        ->assertDontSee('crm.mcaschool.education');
});

it('en creación (sin bot todavía) NO se muestra el snippet', function () {
    [$admin] = embedSetup('admin');

    $this->actingAs($admin);

    Livewire::test(Form::class)
        ->assertOk()
        ->assertDontSee('Incrustar widget');
});

it('Marketing NO puede acceder al formulario del asesor (ni ver el snippet)', function () {
    [$admin, $bot] = embedSetup('admin');
    $marketing = User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'marketing']);

    $this->actingAs($marketing);

    Livewire::test(Form::class, ['bot' => $bot])->assertForbidden();
});
