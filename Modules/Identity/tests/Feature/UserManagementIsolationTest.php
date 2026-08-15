<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Institutions\Models\Institution;

/**
 * Aislamiento multi-institucion de la superficie del panel (gestion de usuarios
 * y cambiador de institucion). Se ejercita por HTTP para pasar por el middleware
 * de contexto real.
 *
 * Regresion del MOTOR multi-tenant dormante: se corre con el flag ACTIVO, para
 * confirmar que el aislamiento sigue intacto si algun dia se habilita el modo
 * multi-institucion. En modo normal (flag false) estas superficies estan ocultas.
 */
beforeEach(function () {
    config(['crm.multi_institution' => true]);
});

function institutionWithUser(string $userName): array
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $institution->id,
        'name' => $userName,
        'role' => 'admissions',
    ]);

    return [$institution, $user];
}

it('el listado de usuarios solo muestra los de la institucion del Admin', function () {
    [$instA] = institutionWithUser('Usuario-A');
    [, $userB] = institutionWithUser('Usuario-B');

    $adminA = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin', 'name' => 'AdminA']);

    $this->actingAs($adminA)->get('/users')
        ->assertOk()
        ->assertSee('Usuario-A')
        ->assertDontSee('Usuario-B');
});

it('un Admin no puede abrir la edicion de un usuario de otra institucion', function () {
    [$instA] = institutionWithUser('Usuario-A');
    [, $userB] = institutionWithUser('Usuario-B');

    $adminA = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin']);

    // El usuario B existe (binding global), pero la Policy lo bloquea por institucion.
    $this->actingAs($adminA)->get("/users/{$userB->id}/edit")->assertForbidden();
});

it('un usuario NO super-admin no puede cambiar de institucion por ninguna via', function () {
    [$instA] = institutionWithUser('Usuario-A');
    [$instB, $userB] = institutionWithUser('Usuario-B');

    $adminA = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin']);

    // Intento de cambio -> 403.
    $this->actingAs($adminA)
        ->post('/institution/switch', ['institution_id' => $instB->id])
        ->assertForbidden();

    // Y su contexto sigue siendo el suyo: ve A, no B.
    $this->actingAs($adminA)->get('/users')
        ->assertSee('Usuario-A')
        ->assertDontSee('Usuario-B');
});

it('un super-admin cambia de institucion activa y ve los datos correspondientes', function () {
    [$instA, $userA] = institutionWithUser('Usuario-A');
    [$instB, $userB] = institutionWithUser('Usuario-B');

    // Super-admin con institucion "de casa" A.
    $super = User::factory()->create([
        'institution_id' => $instA->id,
        'role' => 'admin',
        'is_super_admin' => true,
    ]);

    // Por defecto ve su institucion de casa (A).
    $this->actingAs($super)->get('/users')
        ->assertSee('Usuario-A')
        ->assertDontSee('Usuario-B');

    // Cambia a B.
    $this->actingAs($super)
        ->post('/institution/switch', ['institution_id' => $instB->id])
        ->assertRedirect(route('dashboard'));

    // Ahora ve B, no A.
    $this->actingAs($super)->get('/users')
        ->assertSee('Usuario-B')
        ->assertDontSee('Usuario-A');
});

it('un super-admin con institucion activa A no puede editar un usuario de B', function () {
    [$instA] = institutionWithUser('Usuario-A');
    [, $userB] = institutionWithUser('Usuario-B');

    $super = User::factory()->create([
        'institution_id' => $instA->id,
        'role' => 'admin',
        'is_super_admin' => true,
    ]);

    // Activo = A (de casa); editar un usuario de B esta fuera de la institucion activa.
    $this->actingAs($super)->get("/users/{$userB->id}/edit")->assertForbidden();
});
