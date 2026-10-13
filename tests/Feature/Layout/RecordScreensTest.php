<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Create;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadNote;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/*
| Fichas y formularios (modernización visual, etapa 4): ficha de lead y de contacto con la
| estructura de registro del sistema (cabecera, historial, datos, seguimiento) y alta de lead
| con campos del kit. Nada se elimina: mismos bloques, acciones y llamadas wire:.
*/

function recordAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);
    Bot::factory()->create(['type' => 'ia', 'status' => 'active']);

    return $user;
}

it('la ficha del lead usa la estructura de registro y conserva todos sus bloques y acciones', function () {
    $admin = recordAdmin();
    $lead = Lead::factory()->create();
    LeadNote::create(['lead_id' => $lead->id, 'body' => 'Llamar el lunes', 'author_name' => 'Equipo']);

    $html = $this->actingAs($admin)->get('/crm/leads/'.$lead->id)->assertOk()->getContent();

    expect($html)->not->toContain('class="crm-page')->not->toContain('<x-ui.crm-styles')
        ->toContain('class="ui-card ui-record-h"')->toContain('class="ui-record-grid"')
        ->toContain('aria-label="Secciones de la ficha"');
    // Secciones del historial enlazadas desde la navegación por anclas.
    foreach (['conversation', 'email', 'notes', 'events', 'enrollment', 'transfer'] as $id) {
        expect($html)->toContain('id="'.$id.'"');
    }
    foreach (['#conversation', '#email', '#notes', '#events', '#enrollment'] as $anchor) {
        expect($html)->toContain('href="'.$anchor.'"');
    }
    // Bloques y acciones que existían antes.
    foreach (['Datos personales', 'Resultado del emparejador', 'Transferir seguimiento', 'Notas internas', 'Eventos', 'Matrícula · Moodle', 'Correos enviados', 'Llamar el lunes', 'Acceso a datos personales registrado en auditoría.'] as $text) {
        expect($html)->toContain($text);
    }
    expect($html)->toContain('wire:click="exportOne"')->toContain('wire:click="openCompose"')
        ->toContain('wire:click="addNote"')->toContain('wire:change="transfer"')
        ->toContain("wire:click=\"\$toggle('statusMenuOpen')\"")
        ->toContain(route('crm.agenda.create', ['lead' => $lead->id]));
});

it('la cabecera del lead ya no muestra el «…» decorativo sin acción', function () {
    $admin = recordAdmin();
    $lead = Lead::factory()->create();

    $html = $this->actingAs($admin)->get('/crm/leads/'.$lead->id)->assertOk()->getContent();

    expect($html)->not->toContain('<circle cx="19" cy="12" r="1"/>')
        ->toContain('wire:click="exportOne"')->toContain('wire:click="openCompose"');
});

it('el menú de estado lista todos los estados y desactiva el actual', function () {
    $admin = recordAdmin();
    $lead = Lead::factory()->create(['status' => 'new']);

    $this->actingAs($admin);
    Livewire::test(\Modules\Crm\Livewire\Leads\Show::class, ['lead' => $lead])
        ->set('statusMenuOpen', true)
        ->assertSeeHtml('class="ui-menu" role="menu"')
        ->assertSeeHtml("wire:click=\"changeStatus('contacted')\"")
        ->assertSeeHtml('disabled aria-current="true"');
});

it('el alta de lead marca los campos con error, enlazados a su mensaje', function () {
    $this->actingAs(recordAdmin());

    Livewire::test(Create::class)
        ->set('country', 'Colombia')
        ->call('save')
        ->assertHasErrors(['first_name', 'email', 'country'])
        ->assertSeeHtml('id="lc-first_name-error"')
        ->assertSeeHtml('aria-describedby="lc-email-error"')
        ->assertSeeHtml('aria-invalid="true"')
        ->assertSeeHtml('class="ui-input is-invalid"');
});

it('la ficha del contacto usa la estructura de registro con leads, intereses, conversaciones y eventos', function () {
    $admin = recordAdmin();
    $contact = Contact::factory()->create(['first_name' => 'Ana', 'last_name' => 'Prueba', 'country' => 'CO']);
    Lead::factory()->create(['contact_id' => $contact->id]);

    $html = $this->actingAs($admin)->get('/crm/contacts/'.$contact->id)->assertOk()->getContent();
    expect($html)->not->toContain('class="mca-panel')
        ->toContain('class="ui-card ui-record-h"')->toContain('Ana Prueba')
        ->toContain('id="leads"')->toContain('id="interests"')->toContain('id="conversations"')->toContain('id="events"')
        ->toContain('Datos del contacto')->toContain('Consentimiento')->toContain('1 lead');
});
