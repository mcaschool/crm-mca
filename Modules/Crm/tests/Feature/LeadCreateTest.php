<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Create;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function createActor(string $role = 'admissions', ?string $department = null): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $institution->id,
        'role' => $role,
        'department' => $department,
        'status' => 'active',
    ]);
    app(CurrentInstitution::class)->set($institution->id);
    // Asesor de IA activo (Celia) para asignar el lead.
    Bot::factory()->create(['type' => 'ia', 'status' => 'active']);

    return $user;
}

it('Admin crea un lead manual (contacto + lead) con source manual', function () {
    $this->actingAs(createActor('admin'));

    Livewire::test(Create::class)
        ->set('first_name', 'Nuevo')
        ->set('last_name', 'Prospecto')
        ->set('email', 'nuevo@example.com')
        ->set('area', 'Liderazgo')
        ->set('goal', 'ascenso')
        ->set('interest_level', 'high')
        ->call('save');

    $contact = Contact::where('email', 'nuevo@example.com')->first();
    expect($contact)->not->toBeNull();
    expect($contact->first_name)->toBe('Nuevo');

    $lead = Lead::where('contact_id', $contact->id)->first();
    expect($lead)->not->toBeNull();
    expect($lead->source)->toBe('manual');
    expect($lead->area)->toBe('Liderazgo');
    expect($lead->status->value)->toBe('new');
});

it('el alta manual NO duplica si el correo ya existe (enriquece)', function () {
    $this->actingAs(createActor('admin'));

    $existing = Contact::factory()->create(['email' => 'repetido@example.com', 'first_name' => 'Viejo']);

    Livewire::test(Create::class)
        ->set('first_name', 'Actualizado')
        ->set('email', 'repetido@example.com')
        ->call('save');

    expect(Contact::where('email', 'repetido@example.com')->count())->toBe(1);
    expect($existing->fresh()->first_name)->toBe('Actualizado');
});

it('valida nombre y correo requeridos', function () {
    $this->actingAs(createActor('admin'));

    Livewire::test(Create::class)
        ->set('first_name', '')
        ->set('email', 'no-es-correo')
        ->call('save')
        ->assertHasErrors(['first_name', 'email']);
});

it('Marketing NO puede abrir el alta manual (solo lectura)', function () {
    $this->actingAs(createActor('marketing'));

    Livewire::test(Create::class)->assertForbidden();
});

it('Soporte NO puede crear leads a mano', function () {
    $this->actingAs(createActor('admissions', 'Soporte'));

    Livewire::test(Create::class)->assertForbidden();
});

it('Admisiones y Academico SI pueden crear leads a mano', function () {
    foreach ([['admissions', 'Admisiones'], ['admissions', 'Académico']] as [$role, $dept]) {
        $this->actingAs(createActor($role, $dept));
        Livewire::test(Create::class)->assertOk();
    }
});
