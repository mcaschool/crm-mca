<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Institutions\Models\Institution;

/**
 * Acceso al panel y gating por rol (control en el BACKEND, no solo en la UI).
 */
function makeUser(string $role, array $overrides = []): User
{
    $institution = $overrides['institution'] ?? Institution::factory()->create();
    unset($overrides['institution']);

    return User::factory()->create(array_merge([
        'institution_id' => $institution->id,
        'role' => $role,
    ], $overrides));
}

it('redirige al login a un invitado que entra al panel', function () {
    $this->get('/users')->assertRedirect('/login');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('un Administrador puede entrar a la gestion de usuarios', function () {
    $admin = makeUser('admin');

    $this->actingAs($admin)->get('/users')->assertOk();
});

it('Marketing NO puede entrar a la gestion de usuarios', function () {
    $user = makeUser('marketing');

    $this->actingAs($user)->get('/users')->assertForbidden();
});

it('Admisiones NO puede entrar a la gestion de usuarios', function () {
    $user = makeUser('admissions');

    $this->actingAs($user)->get('/users')->assertForbidden();
});

it('Marketing y Admisiones si pueden ver el dashboard (cascaron)', function () {
    $this->actingAs(makeUser('marketing'))->get('/dashboard')->assertOk();
    $this->actingAs(makeUser('admissions'))->get('/dashboard')->assertOk();
});

it('un usuario desactivado no puede entrar al panel', function () {
    $user = makeUser('admin', ['status' => 'inactive']);

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

it('un usuario desactivado no puede autenticarse aunque la contrasena sea valida', function () {
    $user = makeUser('admin', ['status' => 'inactive', 'email' => 'off@example.com']);

    $this->post('/login', ['email' => 'off@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
