<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->set('department', 'Marketing')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $created = User::query()->where('email', 'mkt@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->institution_id)->toBe($inst->id);
    expect($created->role->value)->toBe('marketing');
    expect($created->department)->toBe('Marketing');
    expect($created->is_super_admin)->toBeFalse();
});

it('al crear NO se pide contrasena; se genera un enlace de acceso por invitacion', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);

    // Sin password: el usuario fija su clave por invitacion.
    Livewire::test(Form::class)
        ->set('name', 'Por Invitacion')
        ->set('email', 'invita@example.com')
        ->set('role', 'admissions')
        ->call('save')
        ->assertHasNoErrors();

    $created = User::query()->where('email', 'invita@example.com')->first();
    expect($created)->not->toBeNull();
    // No hay contrasena en claro: el hash existe pero es inutilizable/desconocido.
    expect($created->password)->not->toBeEmpty();

    // Reenviar invitacion genera un enlace de restablecimiento visible al Admin.
    Livewire::test(Form::class, ['user' => $created])
        ->call('regenerateInvitation')
        ->assertSee('reset-password');
});

it('un Admin normal NO puede otorgar super-admin', function () {
    $inst = Institution::factory()->create();
    adminIn($inst); // admin no-super

    Livewire::test(Form::class)
        ->set('name', 'Intento Super')
        ->set('email', 'super@example.com')
        ->set('role', 'admin')
        ->set('is_super_admin', true)
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
        ->assertHasNoErrors();

    expect($target->fresh()->name)->toBe('Nuevo Nombre');
});

it('el correo NO es editable tras crear (identidad de login inmutable)', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);
    $target = User::factory()->create(['institution_id' => $inst->id, 'email' => 'fijo@example.com']);

    // Aunque se intente cambiar el email en el estado, save no lo toca al editar.
    Livewire::test(Form::class, ['user' => $target])
        ->set('email', 'otro@example.com')
        ->set('name', 'Cambio')
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->email)->toBe('fijo@example.com');
});

it('el numero de identidad se guarda CIFRADO y se enmascara', function () {
    $inst = Institution::factory()->create();
    adminIn($inst);

    Livewire::test(Form::class)
        ->set('name', 'Con ID')
        ->set('email', 'conid@example.com')
        ->set('role', 'admissions')
        ->set('nationalId', '0102030405')
        ->call('save')
        ->assertHasNoErrors();

    $u = User::query()->where('email', 'conid@example.com')->first();
    // En claro via el modelo (cast encrypted).
    expect($u->national_id)->toBe('0102030405');
    // En BD, cifrado: la columna cruda NO contiene el numero en claro.
    $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('national_id');
    expect($raw)->not->toContain('0102030405');
    // Enmascarado para la UI.
    expect($u->maskedNationalId())->toEndWith('0405');
    expect($u->maskedNationalId())->not->toContain('010203');
});

it('el Admin sube y quita la foto de perfil de un usuario', function () {
    Storage::fake('public');
    $inst = Institution::factory()->create();
    adminIn($inst);
    $target = User::factory()->create(['institution_id' => $inst->id]);

    $comp = Livewire::test(Form::class, ['user' => $target]);
    $comp->set('avatar', UploadedFile::fake()->image('foto.png', 150, 150))->call('saveAvatar')->assertHasNoErrors();

    $target->refresh();
    expect($target->avatar_path)->toBe('users/'.$target->id.'/avatar.png');
    Storage::disk('public')->assertExists('users/'.$target->id.'/avatar.png');
    expect($target->avatarUrl())->toContain('users/'.$target->id.'/avatar.png');

    $comp->call('removeAvatar');
    expect($target->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing('users/'.$target->id.'/avatar.png');
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
