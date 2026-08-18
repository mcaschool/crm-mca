<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Dashboard;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/** Usuario del panel + contexto de institucion activo. */
function dashUser(string $role = 'admin', ?string $department = null): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $institution->id,
        'role' => $role,
        'department' => $department,
        'status' => 'active',
    ]);
    app(CurrentInstitution::class)->set($institution->id);

    return $user;
}

it('el dashboard de Admin muestra el panorama general y actividad', function () {
    $this->actingAs(dashUser('admin'));

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Nuevos sin contactar')
        ->assertSee('Actividad reciente')
        ->assertSee('Administrador');
});

it('el dashboard de Admisiones es operativo (leads nuevos + embudo) y ve Nuevo lead', function () {
    $this->actingAs(dashUser('admissions', 'Admisiones'));

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Leads nuevos sin contactar')
        ->assertSee('Embudo de estados')
        ->assertSee('Nuevo lead');
});

it('el dashboard de Academico pone los referidos en primer plano', function () {
    $this->actingAs(dashUser('admissions', 'Académico'));

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Mis referidos')
        ->assertSee('Referidos a ti');
});

it('el dashboard de Soporte muestra sus casos referidos y NO puede crear leads', function () {
    $this->actingAs(dashUser('admissions', 'Soporte'));

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Mis casos referidos')
        ->assertDontSee('Nuevo lead');
});

it('el dashboard de Marketing muestra graficas y NO puede crear leads', function () {
    $this->actingAs(dashUser('marketing'));

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Leads por área')
        ->assertSee('Embudo de estados')
        ->assertDontSee('Nuevo lead');
});

it('los widgets reflejan datos reales (leads nuevos y corporativo)', function () {
    $user = dashUser('admin');
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $contacts = Contact::factory()->count(3)->create();
    foreach ($contacts as $c) {
        Lead::factory()->create(['contact_id' => $c->id, 'bot_id' => $bot->id, 'status' => 'new']);
    }
    Event::factory()->create(['contact_id' => $contacts[0]->id, 'bot_id' => $bot->id, 'event_type' => 'corporate_interest']);

    Livewire::test(Dashboard::class)
        ->assertSee('Nuevos sin contactar')
        ->assertSee('3')  // 3 leads nuevos
        ->assertSee('Interés corporativo');
});

it('la grafica Leads por area cuenta por area con datos reales', function () {
    $user = dashUser('admin');
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    Lead::factory()->count(2)->create(['bot_id' => $bot->id, 'area' => 'Liderazgo']);
    Lead::factory()->create(['bot_id' => $bot->id, 'area' => 'Comunicación']);

    Livewire::test(Dashboard::class)
        ->assertSee('Liderazgo')
        ->assertSee('Comunicación');
});
