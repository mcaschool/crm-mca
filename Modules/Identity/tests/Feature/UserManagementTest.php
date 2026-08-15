<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Users\Form;
use Modules\Identity\Livewire\Users\Index;
use Modules\Institutions\Models\Institution;

/**
 * Gestion de usuarios del panel (Livewire), acotada a la institucion activa.
 */
function adminIn(Institution $institution, bool $super = false): User
{
    $admin = User::factory()->create([
        'institution_id' => $institution->id,
        'role' => 'admin',
        'is_super_admin' => $super,
    ]);

    // Contexto activo = la institucion del admin (lo que hace el middleware).
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    return $admin;
}

it('un Admin crea un usuario sellado a su institucion activa', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);

    Livewire::test(Form::class)
        ->set('name', 'Nueva Marketing')
        ->set('email', 'mkt@example.com')
        ->set('role', 'marketing')
        ->set('password', 'password123')
        ->call('save')
        ->assertRedirect(route('users.index'));

    $created = User::query()->where('email', 'mkt@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->institution_id)->toBe($inst->id);
    expect($created->role->value)->toBe('marketing');
    expect($created->is_super_admin)->toBeFalse();
});

it('exige contrasena al crear', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);

    Livewire::test(Form::class)
        ->set('name', 'Sin Clave')
        ->set('email', 'sinclave@example.com')
        ->set('role', 'admissions')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors(['password']);

    expect(User::query()->where('email', 'sinclave@example.com')->exists())->toBeFalse();
});

it('un Admin normal NO puede otorgar super-admin', function () {
    $inst = Institution::factory()->create();
    adminIn($inst); // admin no-super

    Livewire::test(Form::class)
        ->set('name', 'Intento Super')
        ->set('email', 'super@example.com')
        ->set('role', 'admin')
        ->set('is_super_admin', true)
        ->set('password', 'password123')
        ->call('save');

    $created = User::query()->where('email', 'super@example.com')->first();
    expect($created)->not->toBeNull();
    // La barandilla ignora el flag: un no-super no puede crear un super.
    expect($created->is_super_admin)->toBeFalse();
});

it('un super-admin SI puede otorgar super-admin', function () {
    // Otorgar super-admin es superficie multi-institucion: requiere el flag activo.
    config(['crm.multi_institution' => true]);

    $inst = Institution::factory()->create();
    adminIn($inst, super: true);

    Livewire::test(Form::class)
        ->set('name', 'Otro Super')
        ->set('email', 'otrosuper@example.com')
        ->set('role', 'admin')
        ->set('is_super_admin', true)
        ->set('password', 'password123')
        ->call('save');

    $created = User::query()->where('email', 'otrosuper@example.com')->first();
    expect($created->is_super_admin)->toBeTrue();
});

it('el Admin puede editar un usuario de su institucion', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);
    $target = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'name' => 'Viejo']);

    Livewire::test(Form::class, ['user' => $target])
        ->assertSet('name', 'Viejo')
        ->set('name', 'Nuevo Nombre')
        ->call('save')
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->name)->toBe('Nuevo Nombre');
});

it('el listado solo muestra usuarios de la institucion activa', function () {
    $inst = Institution::factory()->create();
    $admin = adminIn($inst);
    $mine = User::factory()->create(['institution_id' => $inst->id, 'name' => 'Propio']);

    // Usuario de OTRA institucion, no debe aparecer.
    $other = Institution::factory()->create();
    User::factory()->create(['institution_id' => $other->id, 'name' => 'Ajeno']);

    Livewire::test(Index::class)
        ->assertSee('Propio')
        ->assertDontSee('Ajeno');
});
