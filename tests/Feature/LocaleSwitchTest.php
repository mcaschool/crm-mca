<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

function localeUser(string $lang = 'es'): User
{
    $inst = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $inst->id, 'preferred_language' => $lang]);
    app(CurrentInstitution::class)->set($inst->id);

    return $user;
}

it('cambiar idioma persiste preferred_language y el panel pasa a inglés', function () {
    $user = localeUser('es');

    test()->actingAs($user)->from('/dashboard')->post('/locale', ['lang' => 'en'])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->preferred_language)->toBe('en');
});

it('vuelve a español al elegir ES', function () {
    $user = localeUser('en');

    test()->actingAs($user)->post('/locale', ['lang' => 'es']);

    expect($user->fresh()->preferred_language)->toBe('es');
});

it('ignora un idioma no soportado (no cambia la preferencia)', function () {
    $user = localeUser('es');

    test()->actingAs($user)->post('/locale', ['lang' => 'fr']);

    expect($user->fresh()->preferred_language)->toBe('es');
});

it('un invitado no puede cambiar el idioma del panel', function () {
    test()->post('/locale', ['lang' => 'en'])->assertRedirect('/login');
});
