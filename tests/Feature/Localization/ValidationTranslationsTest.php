<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Create;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/*
| Mensajes de validación (etapa 4.1): con el idioma activo en español los formularios
| mostraban claves como «validation.required» porque faltaba lang/es/validation.php.
| Se comprueba que ambos idiomas tienen las mismas claves, que los mensajes se traducen
| con nombres de campo legibles y que llegan así al alta de lead y al editor de correo.
*/

function localeAdmin(string $locale): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create([
        'institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active', 'preferred_language' => $locale,
    ]);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);
    Bot::factory()->create(['type' => 'ia', 'status' => 'active']);
    app()->setLocale($locale);

    return $user;
}

/** @return array<int, string> */
function langKeys(string $locale, string $group): array
{
    return array_keys(Arr::dot(require lang_path($locale.'/'.$group.'.php')));
}

it('español e inglés definen exactamente las mismas claves de validación, contraseñas y paginación', function (string $group) {
    $es = langKeys('es', $group);
    $en = langKeys('en', $group);

    expect(array_values(array_diff($es, $en)))->toBe([])
        ->and(array_values(array_diff($en, $es)))->toBe([]);
})->with(['validation', 'passwords', 'pagination']);

it('ninguna regla de validación devuelve su clave sin traducir', function (string $locale) {
    app()->setLocale($locale);
    $rules = array_filter(langKeys($locale, 'validation'), fn ($k) => ! str_starts_with($k, 'custom.') && ! str_starts_with($k, 'attributes.'));

    expect(count($rules))->toBeGreaterThan(100);
    foreach ($rules as $key) {
        expect(__('validation.'.$key))->not->toBe('validation.'.$key);
    }
    expect(__('passwords.sent'))->not->toBe('passwords.sent');
})->with(['es', 'en']);

it('traduce los mensajes con el nombre legible del campo según el idioma activo', function (string $locale, array $expected) {
    app()->setLocale($locale);

    $errors = Validator::make(
        ['first_name' => '', 'country' => 'Colombia', 'email' => 'no-es-correo'],
        ['first_name' => 'required', 'country' => 'size:2', 'email' => 'email'],
    )->errors();

    expect($errors->first('first_name'))->toBe($expected[0])
        ->and($errors->first('country'))->toBe($expected[1])
        ->and($errors->first('email'))->toBe($expected[2]);
})->with([
    'español' => ['es', [
        'El campo nombre es obligatorio.',
        'El campo país debe tener exactamente 2 caracteres.',
        'El campo correo debe ser una dirección de correo válida (por ejemplo, nombre@dominio.com).',
    ]],
    'inglés' => ['en', [
        'The name field is required.',
        'The country field must be 2 characters.',
        'The email field must be a valid email address.',
    ]],
]);

it('el alta de lead muestra los errores traducidos junto a cada campo', function (string $locale, array $expected) {
    $this->actingAs(localeAdmin($locale));

    $component = Livewire::test(Create::class)
        ->set('country', 'Colombia')
        ->call('save')
        ->assertHasErrors(['first_name', 'email', 'country'])
        ->assertDontSee('validation.');

    expect($component->errors()->first('first_name'))->toBe($expected[0])
        ->and($component->errors()->first('country'))->toBe($expected[1]);
    $component->assertSee($expected[0])->assertSee($expected[1]);
})->with([
    'español' => ['es', ['El campo nombre es obligatorio.', 'El campo país debe tener exactamente 2 caracteres.']],
    'inglés' => ['en', ['The name field is required.', 'The country field must be 2 characters.']],
]);

it('el editor de correo valida en el idioma activo sin enviar nada', function (string $locale, array $expected) {
    $this->actingAs(localeAdmin($locale));
    $lead = Lead::factory()->create();

    $component = Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->call('sendEmail')
        ->assertHasErrors(['emailSenderId', 'emailSubject', 'emailBody'])
        ->assertDontSee('validation.');

    expect($component->errors()->first('emailSenderId'))->toBe($expected[0])
        ->and($component->errors()->first('emailSubject'))->toBe($expected[1])
        ->and($component->errors()->first('emailBody'))->toBe($expected[2]);
})->with([
    'español' => ['es', ['El campo remitente es obligatorio.', 'El campo asunto es obligatorio.', 'El campo mensaje es obligatorio.']],
    'inglés' => ['en', ['The sender field is required.', 'The subject field is required.', 'The message field is required.']],
]);

it('el campo País explica el formato de 2 letras sin cambiar su validación', function () {
    $this->actingAs(localeAdmin('es'));

    Livewire::test(Create::class)
        ->assertSeeHtml('id="lc-country-help"')
        ->assertSeeHtml('aria-describedby="lc-country-help"')
        ->assertSee('Código ISO del país en 2 letras, p. ej. CO (Colombia)')
        ->assertSeeHtml('maxlength="2"');
});
