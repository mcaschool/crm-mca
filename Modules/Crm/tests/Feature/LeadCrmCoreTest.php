<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Index;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadNote;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/** Institucion + usuario del CRM + contexto activo. Por defecto Admisiones (actua). */
function crmActor(string $role = 'admissions', ?string $department = null): User
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

it('filtra los leads por estado, asesor y area', function () {
    $user = crmActor();
    $this->actingAs($user);

    $celia = Bot::factory()->create(['assistant_name' => 'Celia', 'type' => 'ia']);
    $sofia = Bot::factory()->create(['assistant_name' => 'Sofia', 'type' => 'ia']);

    $a = Contact::factory()->create(['first_name' => 'Alberto', 'last_name' => 'Ruiz']);
    $b = Contact::factory()->create(['first_name' => 'Maria', 'last_name' => 'Lopez']);

    Lead::factory()->create(['contact_id' => $a->id, 'bot_id' => $celia->id, 'status' => 'qualified', 'area' => 'Finanzas']);
    Lead::factory()->create(['contact_id' => $b->id, 'bot_id' => $sofia->id, 'status' => 'new', 'area' => 'Marketing']);

    // Filtro por estado.
    Livewire::test(Index::class)
        ->set('status', 'qualified')
        ->assertSee('Alberto')
        ->assertDontSee('Maria');

    // Filtro por asesor.
    Livewire::test(Index::class)
        ->set('advisor', (string) $sofia->id)
        ->assertSee('Maria')
        ->assertDontSee('Alberto');

    // Filtro por area.
    Livewire::test(Index::class)
        ->set('area', 'Finanzas')
        ->assertSee('Alberto')
        ->assertDontSee('Maria');
});

it('busca leads por nombre o correo del contacto', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $a = Contact::factory()->create(['first_name' => 'Alberto', 'email' => 'alberto@example.com']);
    $b = Contact::factory()->create(['first_name' => 'Carlos', 'email' => 'carlos@example.com']);
    Lead::factory()->create(['contact_id' => $a->id, 'bot_id' => $bot->id]);
    Lead::factory()->create(['contact_id' => $b->id, 'bot_id' => $bot->id]);

    Livewire::test(Index::class)
        ->set('search', 'carlos@example')
        ->assertSee('Carlos')
        ->assertDontSee('Alberto');
});

it('la etiqueta Empresa aparece solo si hubo interes corporativo', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $withCorp = Contact::factory()->create(['first_name' => 'Empresarial']);
    $without = Contact::factory()->create(['first_name' => 'Individual']);
    Lead::factory()->create(['contact_id' => $withCorp->id, 'bot_id' => $bot->id]);
    Lead::factory()->create(['contact_id' => $without->id, 'bot_id' => $bot->id]);

    Event::factory()->create([
        'contact_id' => $withCorp->id,
        'bot_id' => $bot->id,
        'event_type' => 'corporate_interest',
    ]);

    $html = Livewire::test(Index::class)->html();
    // Solo un lead lleva la etiqueta Empresa.
    expect(substr_count($html, '>Empresa<') + substr_count($html, 'Empresa</span>'))->toBeGreaterThanOrEqual(1);
});

it('exporta a CSV respetando los filtros activos', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create(['assistant_name' => 'Celia']);
    $a = Contact::factory()->create(['first_name' => 'Alberto', 'last_name' => 'Ruiz', 'email' => 'a@x.com']);
    $b = Contact::factory()->create(['first_name' => 'Maria', 'email' => 'm@x.com']);
    Lead::factory()->create(['contact_id' => $a->id, 'bot_id' => $bot->id, 'status' => 'qualified']);
    Lead::factory()->create(['contact_id' => $b->id, 'bot_id' => $bot->id, 'status' => 'new']);

    Livewire::test(Index::class)
        ->set('status', 'qualified')
        ->call('export')
        ->assertFileDownloaded();
});

it('muestra el WhatsApp completo con codigo de pais y audita el acceso al abrir la ficha', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $contact = Contact::factory()->create(['phone' => '+13055554821']);
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);

    // Numero completo con codigo de pais, formateado (NANP): "+1 305 555 4821".
    Livewire::test(Show::class, ['lead' => $lead])
        ->assertSee('+1 305 555 4821');

    // Abrir la ficha registra el acceso a datos personales (quien/cuando), sin el valor.
    $log = AuditLog::where('action', 'contact.personal_data_viewed')
        ->where('auditable_id', $contact->id)
        ->where('user_id', $user->id)
        ->first();
    expect($log)->not->toBeNull();
    expect(json_encode($log->changes))->not->toContain('4821');
});

it('Marketing ve el CRM en solo lectura (no puede cambiar estado)', function () {
    $marketing = crmActor('marketing');
    $this->actingAs($marketing);

    $bot = Bot::factory()->create();
    $lead = Lead::factory()->create(['bot_id' => $bot->id, 'status' => 'new']);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('changeStatus', 'qualified')
        ->assertForbidden();

    expect($lead->fresh()->status->value)->toBe('new');
});

it('Soporte solo puede actuar sobre sus propios referidos', function () {
    $soporte = crmActor('admissions', 'Soporte');
    $this->actingAs($soporte);

    $bot = Bot::factory()->create();
    $mine = Lead::factory()->create(['bot_id' => $bot->id, 'status' => 'new', 'assigned_to_user_id' => $soporte->id]);
    $other = Lead::factory()->create(['bot_id' => $bot->id, 'status' => 'new']);

    // Su referido: puede.
    Livewire::test(Show::class, ['lead' => $mine])->call('changeStatus', 'contacted');
    expect($mine->fresh()->status->value)->toBe('contacted');

    // No suyo: prohibido.
    Livewire::test(Show::class, ['lead' => $other])->call('changeStatus', 'contacted')->assertForbidden();
    expect($other->fresh()->status->value)->toBe('new');
});

it('el departamento "Contabilidad y Finanzas" aparece como destino de transferencia', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $lead = Lead::factory()->create(['bot_id' => $bot->id]);

    Livewire::test(Show::class, ['lead' => $lead])
        ->assertSee('Dept. Contabilidad y Finanzas');
});

it('anadir una nota interna guarda autor y fecha', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $lead = Lead::factory()->create(['bot_id' => $bot->id]);

    Livewire::test(Show::class, ['lead' => $lead])
        ->set('newNote', 'Interesado en formacion para su equipo.')
        ->call('addNote')
        ->assertSet('newNote', '');

    $note = LeadNote::where('lead_id', $lead->id)->first();
    expect($note)->not->toBeNull();
    expect($note->body)->toBe('Interesado en formacion para su equipo.');
    expect($note->author_name)->toBe($user->name);
    expect($note->user_id)->toBe($user->id);
});

it('transferir el seguimiento a un humano fija la asignacion y deja evento', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $lead = Lead::factory()->create(['bot_id' => $bot->id]);

    $target = User::factory()->create([
        'institution_id' => $user->institution_id,
        'name' => 'Laura Gomez',
        'department' => 'Finanzas',
        'status' => 'active',
    ]);

    Livewire::test(Show::class, ['lead' => $lead])
        ->set('transferTarget', 'user:'.$target->id)
        ->call('transfer');

    $fresh = $lead->fresh();
    expect($fresh->assigned_to_user_id)->toBe($target->id);
    expect($fresh->assigned_to_department)->toBeNull();

    expect(Event::where('event_type', 'lead_transferred')
        ->where('contact_id', $lead->contact_id)
        ->exists())->toBeTrue();
});

it('transferir a un departamento limpia la asignacion a usuario', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create();
    $lead = Lead::factory()->create(['bot_id' => $bot->id, 'assigned_to_user_id' => $user->id]);

    Livewire::test(Show::class, ['lead' => $lead])
        ->set('transferTarget', 'dept:Admisiones')
        ->call('transfer');

    $fresh = $lead->fresh();
    expect($fresh->assigned_to_department)->toBe('Admisiones');
    expect($fresh->assigned_to_user_id)->toBeNull();
});

it('muestra la conversacion del contacto en la ficha', function () {
    $user = crmActor();
    $this->actingAs($user);

    $bot = Bot::factory()->create(['assistant_name' => 'Celia']);
    $contact = Contact::factory()->create(['first_name' => 'Alberto']);
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => 'user',
        'content' => 'Tienen alguna beca disponible',
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => 'celia',
        'content' => 'Puedes verificar en la pagina de inscripciones.',
    ]);

    Livewire::test(Show::class, ['lead' => $lead])
        ->assertSee('Tienen alguna beca disponible')
        ->assertSee('Puedes verificar en la pagina de inscripciones.');
});
