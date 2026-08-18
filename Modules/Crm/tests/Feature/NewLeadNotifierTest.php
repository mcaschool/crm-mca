<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Dashboard;
use Modules\Crm\Livewire\NewLeadNotifier;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/** Usuario del panel + contexto de institucion activo. */
function notifierUser(string $role = 'admin'): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $institution->id,
        'role' => $role,
        'status' => 'active',
    ]);
    app(CurrentInstitution::class)->set($institution->id);

    return $user;
}

it('no avisa de leads que ya existian al abrir el panel (linea base)', function () {
    $this->actingAs(notifierUser());
    Lead::factory()->create(); // widget lead pre-existente

    // Al montar, la linea base incluye ese lead -> el primer sondeo no avisa.
    Livewire::test(NewLeadNotifier::class)
        ->call('check')
        ->assertNotDispatched('crm-new-leads');
});

it('avisa (una vez, agrupado) de un lead nuevo del widget con nombre y area', function () {
    $this->actingAs(notifierUser());

    $comp = Livewire::test(NewLeadNotifier::class); // linea base = 0 (sin leads)

    $c = Contact::factory()->create(['first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $lead = Lead::factory()->create(['contact_id' => $c->id, 'area' => 'Liderazgo', 'source' => 'widget_microcredenciales']);

    $comp->call('check')
        ->assertDispatched('crm-new-leads', function (string $event, array $params) use ($lead) {
            return $params['count'] === 1
                && $params['title'] === 'Ana Ruiz · Liderazgo'
                && str_contains($params['url'], '/crm/leads/'.$lead->id);
        });
});

it('agrupa varios leads nuevos en un solo aviso que apunta a la lista', function () {
    $this->actingAs(notifierUser());

    $comp = Livewire::test(NewLeadNotifier::class);

    Lead::factory()->count(3)->create(['source' => 'widget_microcredenciales']);

    $comp->call('check')
        ->assertDispatched('crm-new-leads', function (string $event, array $params) {
            return $params['count'] === 3
                && str_contains($params['title'], '3')
                && str_contains($params['url'], '/crm/leads');
        });
});

it('NO avisa de leads que no vienen del widget (p. ej. creados a mano)', function () {
    $this->actingAs(notifierUser());

    $comp = Livewire::test(NewLeadNotifier::class);
    Lead::factory()->create(['source' => 'manual']);

    $comp->call('check')->assertNotDispatched('crm-new-leads');
});

it('no vuelve a avisar del mismo lead en el siguiente sondeo', function () {
    $this->actingAs(notifierUser());

    $comp = Livewire::test(NewLeadNotifier::class);
    Lead::factory()->create(['source' => 'widget_microcredenciales']);

    $comp->call('check')->assertDispatched('crm-new-leads');
    $comp->call('check')->assertNotDispatched('crm-new-leads'); // ya visto
});

it('el toggle de sonido persiste la preferencia en la sesion', function () {
    $this->actingAs(notifierUser());

    $comp = Livewire::test(NewLeadNotifier::class)->assertSet('soundEnabled', false);

    $comp->call('toggleSound')->assertSet('soundEnabled', true);
    expect(session('crm.newlead.sound'))->toBeTrue();

    $comp->call('toggleSound')->assertSet('soundEnabled', false);
    expect(session('crm.newlead.sound'))->toBeFalse();
});

it('respeta el aislamiento por institucion: no avisa de leads de otra institucion', function () {
    $userA = notifierUser();
    $this->actingAs($userA);

    $comp = Livewire::test(NewLeadNotifier::class); // linea base en institucion A

    // Un lead-widget entra en OTRA institucion.
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($instB->id);
    Lead::factory()->create(['source' => 'widget_microcredenciales']);

    // Volvemos al contexto de A: el sondeo de A no debe ver el lead de B.
    app(CurrentInstitution::class)->set((int) $userA->institution_id);
    $comp->call('check')->assertNotDispatched('crm-new-leads');
});

it('el dashboard refresca en vivo al recibir el evento de lead nuevo', function () {
    $this->actingAs(notifierUser('admin'));

    $dash = Livewire::test(Dashboard::class)->assertDontSee('Zoraida');

    // Llega un lead nuevo despues del render inicial.
    $bot = Bot::factory()->create();
    $c = Contact::factory()->create(['first_name' => 'Zoraida', 'last_name' => 'Nueva']);
    Lead::factory()->create(['contact_id' => $c->id, 'bot_id' => $bot->id, 'status' => 'new']);

    // El evento del notificador provoca el re-render -> el nuevo lead aparece.
    $dash->dispatch('crm-new-leads', count: 1, title: 'x', url: 'y')
        ->assertSee('Zoraida');
});
