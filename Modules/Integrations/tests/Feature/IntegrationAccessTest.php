<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Institutions\Models\Institution;

/**
 * Gating por rol del almacen de credenciales: solo Administrador.
 */
function integrationsUser(string $role): User
{
    $institution = Institution::factory()->create();

    return User::factory()->create([
        'institution_id' => $institution->id,
        'role' => $role,
    ]);
}

it('un Administrador accede a Integraciones', function () {
    $this->actingAs(integrationsUser('admin'))->get('/integrations')->assertOk();
});

it('Marketing NO accede a Integraciones', function () {
    $this->actingAs(integrationsUser('marketing'))->get('/integrations')->assertForbidden();
});

it('Admisiones NO accede a Integraciones', function () {
    $this->actingAs(integrationsUser('admissions'))->get('/integrations')->assertForbidden();
});

it('Marketing NO accede a los procesos de IA', function () {
    $this->actingAs(integrationsUser('marketing'))->get('/ai-processes')->assertForbidden();
});

it('un invitado es redirigido al login', function () {
    $this->get('/integrations')->assertRedirect('/login');
});
