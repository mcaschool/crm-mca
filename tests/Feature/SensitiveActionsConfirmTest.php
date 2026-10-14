<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Index as ProgramsIndex;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Users\Index as UsersIndex;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Livewire\Integrations\Index as IntegrationsIndex;
use Modules\Integrations\Models\Integration;
use Modules\Social\Livewire\Channels;
use Modules\Social\Models\SocialChannel;

/*
| Confirmación antes de activar o desactivar usuarios, programas, integraciones (proveedor de IA)
| y canales sociales (etapa 7). La confirmación (wire:confirm) nombra el recurso y la operación; si
| se cancela, el navegador no envía la petición. El servidor sigue exigiendo la autorización.
*/

function confirmAdmin(): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($admin->email)]]);

    return $admin;
}

it('usuarios: la confirmación nombra a la persona y distingue activar de desactivar', function () {
    $admin = confirmAdmin();
    User::factory()->create(['institution_id' => $admin->institution_id, 'name' => 'Lucía Prueba', 'status' => 'active']);
    User::factory()->create(['institution_id' => $admin->institution_id, 'name' => 'Mario Prueba', 'status' => 'inactive']);

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->assertSeeHtml('wire:confirm="¿Desactivar a Lucía Prueba? No podrá iniciar sesión en el panel hasta que se vuelva a activar."')
        ->assertSeeHtml('wire:confirm="¿Activar a Mario Prueba? Podrá volver a iniciar sesión en el panel."');
});

it('catálogo: la confirmación nombra el programa y su efecto', function () {
    $admin = confirmAdmin();
    Program::factory()->create(['name_es' => 'Gestión de Proyectos', 'status' => 'active']);

    Livewire::actingAs($admin)->test(ProgramsIndex::class)
        ->assertSeeHtml('wire:confirm="¿Desactivar el programa «Gestión de Proyectos»? Dejará de ofrecerse en el chat, el emparejador y los asesores."');
});

it('integraciones: el proveedor de IA pide confirmación y advierte del efecto', function () {
    $admin = confirmAdmin();
    Integration::factory()->create(['type' => 'ai_provider', 'status' => 'active']);

    $html = Livewire::actingAs($admin)->test(IntegrationsIndex::class)->html();
    expect($html)->toMatch('~wire:click="toggle\(\d+\)"[^>]*wire:confirm="¿Desactivar la integración «[^»]+»\? Los procesos que la usan~s');
});

it('canales sociales: desactivar un canal pide confirmación con su nombre', function () {
    $admin = confirmAdmin();
    SocialChannel::factory()->create(['provider' => 'whatsapp', 'display_name' => 'Admisiones Prueba', 'external_id' => 'WA_CONF', 'credentials' => ['token' => 'X'], 'is_active' => true]);

    Livewire::actingAs($admin)->test(Channels::class)
        ->assertSeeHtml('wire:confirm="¿Desactivar el canal «Admisiones Prueba»?');
});

it('la confirmación no sustituye la autorización del servidor: una llamada forzada sin permiso no cambia nada', function () {
    $admin = confirmAdmin();
    $program = Program::factory()->create(['status' => 'active']);
    $admissions = User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'admissions', 'status' => 'active']);

    // Un administrador no puede desactivarse a sí mismo aunque fuerce la llamada (no hay botón ni confirmación).
    Livewire::actingAs($admin)->test(UsersIndex::class)->call('toggleActive', $admin->id)->assertForbidden();
    // Un rol sin acceso no llega a montar las pantallas.
    Livewire::actingAs($admissions)->test(UsersIndex::class)->assertForbidden();
    Livewire::actingAs($admissions)->test(ProgramsIndex::class)->assertForbidden();

    expect($admin->fresh()->status)->toBe('active')->and($program->fresh()->status)->toBe('active');
});
