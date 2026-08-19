<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Livewire\Settings;
use Modules\Institutions\Models\Institution;

/**
 * @return array{0: User, 1: Institution}
 */
function settingsActor(string $role = 'admin'): array
{
    $inst = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => $role]);
    app(CurrentInstitution::class)->set($inst->id);
    test()->actingAs($user);

    return [$user, $inst];
}

it('el Admin sube el logo institucional y se guarda en el disco public', function () {
    Storage::fake('public');
    [$admin, $inst] = settingsActor('admin');

    Livewire::test(Settings::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 220, 80))
        ->call('saveLogo')
        ->assertHasNoErrors();

    $inst->refresh();
    expect($inst->logo_path)->toBe('institutions/'.$inst->id.'/logo.png');
    Storage::disk('public')->assertExists($inst->logo_path);
    expect($inst->logoUrl())->not->toBeNull();
});

it('quita el logo y vuelve al fallback', function () {
    Storage::fake('public');
    [$admin, $inst] = settingsActor('admin');

    Livewire::test(Settings::class)->set('logo', UploadedFile::fake()->image('logo.png'))->call('saveLogo');
    expect($inst->fresh()->logo_path)->not->toBeNull();

    Livewire::test(Settings::class)->call('removeLogo');
    expect($inst->fresh()->logo_path)->toBeNull();
});

it('rechaza un archivo que no es imagen', function () {
    Storage::fake('public');
    settingsActor('admin');

    Livewire::test(Settings::class)
        ->set('logo', UploadedFile::fake()->create('malicioso.pdf', 40))
        ->call('saveLogo')
        ->assertHasErrors('logo');
});

it('guarda el tamaño del logo (px) dentro del rango y el sidebar lo respeta', function () {
    [$admin, $inst] = settingsActor('admin');

    Livewire::test(Settings::class)
        ->set('logoSize', 64)
        ->call('saveSize')
        ->assertHasNoErrors();

    expect($inst->fresh()->logo_size)->toBe(64);
    expect($inst->fresh()->logoSize())->toBe(64);
});

it('rechaza un tamaño de logo fuera de rango', function () {
    settingsActor('admin');

    Livewire::test(Settings::class)
        ->set('logoSize', 500)
        ->call('saveSize')
        ->assertHasErrors('logoSize');
});

it('el tamaño por defecto del logo es 44 y se acota', function () {
    [$admin, $inst] = settingsActor('admin');

    expect($inst->logoSize())->toBe(44);           // default
    $inst->update(['logo_size' => 5]);             // fuera de rango bajo
    expect($inst->fresh()->logoSize())->toBe(24);  // acotado al mínimo
});

it('Marketing NO accede a Ajustes por URL directa', function () {
    settingsActor('marketing');

    test()->get('/ajustes')->assertForbidden();
});

it('el Admin accede a Ajustes', function () {
    settingsActor('admin');

    test()->get('/ajustes')->assertOk()->assertSee('Logo institucional');
});
