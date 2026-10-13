<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/*
| Extensión del sistema de diseño (etapa 3): Contactos, Auditoría, Catálogo, Asesores e
| Integraciones usan solo los componentes compartidos; filtros plegables en móvil; acciones
| con nombre accesible. Sin cambios de lógica: las acciones son las mismas llamadas wire:.
*/

function extAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    return $user;
}

it('el grupo de filtros indica cuántos hay aplicados y conserva los selectores', function () {
    $admin = extAdmin();

    $leads = $this->actingAs($admin)->get('/crm/leads?status=new&area=Liderazgo')->assertOk()->getContent();
    expect($leads)->toContain('class="ui-filters"')->toContain('ui-filters-toggle')
        ->toMatch('~ui-badge--count ui-tone--blue">2<span class="ui-sr">~')
        ->toContain('wire:model.live="status"')->toContain('wire:model.live="advisor"')->toContain('wire:model.live="area"');

    // Sin filtros aplicados no hay contador.
    $users = $this->actingAs($admin)->get('/users')->assertOk()->getContent();
    expect($users)->toContain('class="ui-filters"')->not->toContain('ui-badge--count ui-tone--blue');
});

it('Auditoría: filtros plegables, «Limpiar» solo con filtros y tarjeta encabezada por el actor', function () {
    $admin = extAdmin();
    $this->actingAs($admin);
    app(\Modules\Audit\Services\AuditService::class)->log('user.updated', $admin);

    $plain = $this->get('/audit')->assertOk()->getContent();
    expect($plain)->toContain('class="ui-filters"')->not->toContain('wire:click="clearFilters"')
        ->toContain('class="rt-primary"')->toContain('class="rt-aside" data-label="Acción"');

    $filtered = $this->get('/audit?accion=user.updated')->assertOk()->getContent();
    expect($filtered)->toContain('wire:click="clearFilters"')
        ->toMatch('~class="ui-select ui-select--filter is-active"[^>]*wire:model.live="action"~');
});

it('Contactos: persona con enlace a la ficha y datos en tarjeta de tres columnas', function () {
    $admin = extAdmin();
    $contact = Contact::factory()->create(['first_name' => 'Ana', 'last_name' => 'Prueba']);

    $html = $this->actingAs($admin)->get('/crm/contacts')->assertOk()->getContent();
    expect($html)->toContain('class="ui-table ui-table--meta3"')
        ->toContain('<a class="ui-person-name" href="'.route('crm.contacts.show', $contact).'">Ana Prueba</a>')
        ->toContain('data-label="País"')->toContain('data-label="Leads"')->toContain('data-label="Conversaciones"');
});

it('Catálogo: ordenar, editar y activar conservan sus acciones con nombre accesible', function () {
    $admin = extAdmin();
    $p = Program::factory()->create(['name_es' => 'Programa Uno', 'level' => null, 'goal' => null, 'profile' => null]);

    $html = $this->actingAs($admin)->get('/catalog')->assertOk()->getContent();
    expect($html)->toContain('wire:click="moveUp('.$p->id.')"')->toContain('wire:click="moveDown('.$p->id.')"')
        ->toContain('aria-label="Subir Programa Uno"')->toContain('aria-label="Desactivar Programa Uno"')
        ->toContain('wire:click="toggleActive('.$p->id.')"')
        ->toContain('href="'.route('catalog.programs.edit', $p).'"')
        ->toContain('Sin etiquetas')                       // aviso del emparejador, ahora como insignia
        ->toContain('class="rt-foot" data-label="Orden"');
});

it('Asesores e Integraciones usan la ficha del sistema y mantienen sus acciones', function () {
    $admin = extAdmin();
    $integration = Integration::factory()->create(['institution_id' => $admin->institution_id]);

    $adv = $this->actingAs($admin)->get('/advisors')->assertOk()->getContent();
    expect($adv)->toContain('class="ui-grid"')->toContain('Crear asesor');

    $int = $this->actingAs($admin)->get('/integrations')->assertOk()->getContent();
    expect($int)->toContain('class="ui-card ui-entity"')
        ->toContain('wire:click="test('.$integration->id.')"')->toContain('wire:click="toggle('.$integration->id.')"')
        ->toContain('aria-label="Desactivar Proveedor de IA"')
        ->toContain(route('integrations.configure', 'ai_provider'));
});
