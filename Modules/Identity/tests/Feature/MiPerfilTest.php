<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Profile\MiPerfil;
use Modules\Institutions\Models\Institution;

/**
 * "Mi perfil": el propio usuario solo ve sus datos y solo cambia su contrasena.
 */
function normalUser(): User
{
    $inst = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $inst->id,
        'role' => 'marketing',
        'password' => Hash::make('OldPass123!'),
    ]);
    app(CurrentInstitution::class)->set($inst->id);
    test()->actingAs($user);

    return $user;
}

it('un usuario normal accede a Mi perfil; NO a la gestion de usuarios', function () {
    $user = normalUser();

    test()->get('/mi-perfil')->assertOk();
    // Gestion de usuarios es solo de Admin.
    test()->get('/users')->assertForbidden();
    test()->get('/users/create')->assertForbidden();
});

it('cambia su propia contrasena validando la actual', function () {
    $user = normalUser();

    // Contrasena actual incorrecta -> error.
    Livewire::test(MiPerfil::class)
        ->set('current_password', 'incorrecta')
        ->set('password', 'NuevaClave123!')
        ->set('password_confirmation', 'NuevaClave123!')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    // Actual correcta + fuerte + confirmada -> se actualiza.
    Livewire::test(MiPerfil::class)
        ->set('current_password', 'OldPass123!')
        ->set('password', 'NuevaClave123!')
        ->set('password_confirmation', 'NuevaClave123!')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('NuevaClave123!', $user->fresh()->password))->toBeTrue();
});

it('el propio usuario puede subir y quitar SU foto de perfil', function () {
    Storage::fake('public');
    $user = normalUser();

    $comp = Livewire::test(MiPerfil::class);
    $comp->set('avatar', UploadedFile::fake()->image('yo.png', 120, 120))->call('saveAvatar')->assertHasNoErrors();

    $user->refresh();
    expect($user->avatar_path)->toBe('users/'.$user->id.'/avatar.png');
    Storage::disk('public')->assertExists('users/'.$user->id.'/avatar.png');

    $comp->call('removeAvatar');
    expect($user->fresh()->avatar_path)->toBeNull();
});

it('muestra los datos del usuario y la identidad enmascarada (solo lectura)', function () {
    $inst = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $inst->id, 'role' => 'admissions',
        'name' => 'Ana Ruiz', 'national_id' => '9988776655', 'department' => 'Admisiones',
    ]);
    app(CurrentInstitution::class)->set($inst->id);
    test()->actingAs($user);

    Livewire::test(MiPerfil::class)
        ->assertSee('Ana Ruiz')
        ->assertSee('Admisiones')
        ->assertSee('6655')          // ultimos digitos
        ->assertDontSee('9988776655'); // nunca el numero completo
});
