<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Livewire\Agenda\Form;
use Modules\Crm\Livewire\Agenda\Index;
use Modules\Crm\Livewire\Agenda\MeetingProfileForm;
use Modules\Crm\Livewire\Agenda\Show;
use Modules\Crm\Livewire\Dashboard;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\ActivityReminder;
use Modules\Crm\Models\ActivityReschedule;
use Modules\Crm\Models\AvailabilityException;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Notifications\AgendaNotification;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\AgendaMailer;
use Modules\Crm\Services\Agenda\AvailabilityService;
use Modules\Crm\Services\Agenda\IcsBuilder;
use Modules\Crm\Services\Agenda\ScheduleConflictException;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;

/*
| Agenda (Fase 1). Lunes 19/10/2026; el asesor trabaja en America/Bogota (UTC−5, sin
| horario de verano), así que 10:00 locales = 15:00 UTC.
*/

const AG_TZ = 'America/Bogota';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC')); // 02:00 en Bogotá
});

function agInst(): Institution
{
    return Institution::factory()->create();
}

function agUser(Institution $i, string $role = 'admissions', ?string $department = 'Admisiones'): User
{
    return User::factory()->create(['institution_id' => $i->id, 'role' => $role, 'department' => $department, 'status' => 'active']);
}

function agCtx(Institution $i): void
{
    app(CurrentInstitution::class)->set($i->id);
    // Remitente propio de la institución (único activo → remitente de la Agenda).
    if (EmailSender::query()->doesntExist()) {
        EmailSender::factory()->create();
    }
}

/** @param  array<string,mixed>  $attrs */
function agProfile(User $u, array $attrs = []): MeetingProfile
{
    return MeetingProfile::query()->create(array_merge([
        'user_id' => $u->id,
        'timezone' => AG_TZ,
        'default_medium' => 'google_meet',
        'meet_url' => 'https://meet.google.com/abc-defg-hij',
    ], $attrs));
}

function agAt(string $local): Carbon
{
    return Carbon::parse($local, AG_TZ)->utc();
}

/** @param  array<string,mixed>  $data */
function agCreate(User $actor, array $data = [], bool $confirm = false): Activity
{
    return app(ActivityService::class)->create(array_merge([
        'title' => 'Llamada de orientación',
        'type' => 'video_call',
        'medium' => 'google_meet',
        'starts_at' => agAt('2026-10-19 10:00'),
        'ends_at' => agAt('2026-10-19 10:30'),
        'timezone' => AG_TZ,
        'assigned_to_user_id' => $actor->id,
        'reminders' => [],
    ], $data), $actor, $confirm);
}

function agLead(?User $assignee = null, string $status = 'new'): Lead
{
    $contact = Contact::factory()->create();

    return Lead::factory()->create([
        'contact_id' => $contact->id,
        'bot_id' => Bot::factory()->create()->id,
        'assigned_to_user_id' => $assignee?->id,
        'status' => $status,
    ]);
}

/** @return \Illuminate\Support\Collection<int,OutboundEmail> */
function agMails(string $to, string $subjectPrefix): \Illuminate\Support\Collection
{
    return Mail::sent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo($to) && str_starts_with($m->subjectLine, $subjectPrefix));
}

// 1 · Aislamiento ---------------------------------------------------------------------------

it('aísla la agenda por institución: ni consulta, ni ruta, ni invitados de otra institución', function () {
    $a = agInst();
    $b = agInst();
    agCtx($a);
    $ua = agUser($a);
    agProfile($ua);
    $activity = agCreate($ua, ['title' => 'Reunión secreta A']);

    agCtx($b);
    $ub = agUser($b, 'admin', null);
    $foreignContact = Contact::factory()->create();
    expect(Activity::query()->count())->toBe(0)
        ->and(Activity::query()->find($activity->id))->toBeNull();
    $this->actingAs($ub)->get(route('crm.agenda.show', $activity->id))->assertNotFound();
    $this->actingAs($ub)->get(route('crm.agenda.index'))->assertOk()->assertDontSee('Reunión secreta A');

    // Un contacto de B no puede invitarse a una actividad de A.
    agCtx($a);
    expect(fn () => app(ActivityService::class)->addGuest($activity, $foreignContact->id, null, null))
        ->toThrow(ValidationException::class);
    expect(ActivityGuest::query()->count())->toBe(0);
});

// 2 · Permisos --------------------------------------------------------------------------------

it('permisos: admin ve y gestiona todo, el asesor solo lo suyo y solo lectura no modifica', function () {
    $i = agInst();
    agCtx($i);
    $admin = agUser($i, 'admin', null);
    $advisor = agUser($i);
    $other = agUser($i);
    $readOnly = agUser($i, 'marketing', 'Marketing');
    $own = agCreate($advisor, ['title' => 'Mía']);
    $foreign = agCreate($other, ['title' => 'Ajena']);

    // Admin: todo.
    expect($admin->can('view', $foreign))->toBeTrue()->and($admin->can('update', $foreign))->toBeTrue();
    $byAdmin = agCreate($admin, ['assigned_to_user_id' => $advisor->id, 'starts_at' => agAt('2026-10-19 12:00'), 'ends_at' => agAt('2026-10-19 12:30')]);
    expect($byAdmin->assigned_to_user_id)->toBe($advisor->id);

    // Asesor: solo las suyas; no asigna a otros.
    expect($advisor->can('update', $own))->toBeTrue()
        ->and($advisor->can('view', $foreign))->toBeFalse()
        ->and($advisor->can('update', $foreign))->toBeFalse();
    expect(fn () => agCreate($advisor, ['assigned_to_user_id' => $other->id, 'starts_at' => agAt('2026-10-19 16:00'), 'ends_at' => agAt('2026-10-19 16:30')]))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($advisor)->get(route('crm.agenda.show', $foreign))->assertForbidden();
    Livewire::actingAs($advisor)->test(Index::class)->assertSee('Mía')->assertDontSee('Ajena');

    // Solo lectura: ve, no crea ni modifica.
    expect($readOnly->can('view', $foreign))->toBeTrue()
        ->and($readOnly->can('create', Activity::class))->toBeFalse()
        ->and($readOnly->can('update', $own))->toBeFalse();
    $this->actingAs($readOnly)->get(route('crm.agenda.index'))->assertOk()->assertDontSee(route('crm.agenda.create'));
    $this->actingAs($readOnly)->get(route('crm.agenda.create'))->assertForbidden();
    Livewire::actingAs($readOnly)->test(Show::class, ['activity' => $own])->call('complete')->assertForbidden();
    expect($own->fresh()->status)->toBe(ActivityStatus::Scheduled);
});

// 3 · CRUD ------------------------------------------------------------------------------------

it('crea, edita, completa y cancela una actividad desde la interfaz', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);

    Livewire::actingAs($u)->test(Form::class)
        ->set('title', 'Primera llamada')
        ->set('type', 'call')
        ->set('medium', 'phone')
        ->set('date', '2026-10-19')
        ->set('time', '11:00')
        ->set('duration', 45)
        ->set('timezone', AG_TZ)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $a = Activity::query()->sole();
    expect($a->title)->toBe('Primera llamada')
        ->and($a->starts_at->format('Y-m-d H:i'))->toBe('2026-10-19 16:00') // UTC
        ->and($a->durationMinutes())->toBe(45)
        ->and($a->created_by_user_id)->toBe($u->id)
        ->and($a->status)->toBe(ActivityStatus::Scheduled);

    Livewire::actingAs($u)->test(Form::class, ['activity' => $a])
        ->set('title', 'Llamada editada')
        ->call('save')
        ->assertHasNoErrors();
    expect($a->fresh()->title)->toBe('Llamada editada');

    Livewire::actingAs($u)->test(Show::class, ['activity' => $a])
        ->set('outcome', 'Interesado en el programa')
        ->call('complete');
    $a->refresh();
    expect($a->status)->toBe(ActivityStatus::Completed)->and($a->completed_at)->not->toBeNull()
        ->and($a->outcome)->toBe('Interesado en el programa');

    $b = agCreate($u, ['starts_at' => agAt('2026-10-19 14:00'), 'ends_at' => agAt('2026-10-19 14:30')]);
    Livewire::actingAs($u)->test(Show::class, ['activity' => $b])->set('cancelReason', 'No puede')->call('cancelActivity');
    expect($b->fresh()->status)->toBe(ActivityStatus::Cancelled)->and($b->fresh()->cancelled_at)->not->toBeNull();
});

// 4 · Crear desde un lead ---------------------------------------------------------------------

it('programar desde el lead precarga lead, contacto y asesor, registra el evento y no toca el estado comercial', function () {
    $i = agInst();
    agCtx($i);
    $admin = agUser($i, 'admin', null);
    $advisor = agUser($i);
    agProfile($advisor);
    $lead = agLead($advisor, 'contacted');

    $this->actingAs($admin)->get(route('crm.leads.show', $lead))->assertOk()
        ->assertSee(route('crm.agenda.create', ['lead' => $lead->id]), false)
        ->assertSee('Programar actividad');

    Livewire::withQueryParams(['lead' => (string) $lead->id])->actingAs($admin)->test(Form::class)
        ->assertSet('leadId', $lead->id)
        ->assertSet('contactId', $lead->contact_id)
        ->assertSet('assignedTo', $advisor->id)
        ->set('time', '10:00')
        ->set('date', '2026-10-19')
        ->call('save')
        ->assertHasNoErrors();

    $a = Activity::query()->sole();
    expect($a->lead_id)->toBe($lead->id)->and($a->contact_id)->toBe($lead->contact_id)->and($a->assigned_to_user_id)->toBe($advisor->id);
    $ev = Event::query()->where('event_type', 'activity_scheduled')->sole();
    expect($ev->contact_id)->toBe($lead->contact_id)->and($ev->event_data)->toMatchArray(['activity_id' => $a->id])
        ->and(array_keys($ev->event_data))->not->toContain('title');
    expect($lead->fresh()->status->value)->toBe('contacted');

    // Asignada por otra persona: aviso interno al asesor.
    expect($advisor->notifications()->where('type', AgendaNotification::class)->count())->toBe(1);
});

// 5 · Horario, zona horaria y excepciones -----------------------------------------------------

it('aplica horario semanal, zona horaria del asesor y excepciones de fecha', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    AvailabilityRule::query()->create(['user_id' => $u->id, 'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00']); // lunes
    $svc = app(AvailabilityService::class);

    expect($svc->check($u->id, agAt('2026-10-19 10:00'), agAt('2026-10-19 10:30'))->ok())->toBeTrue()
        ->and($svc->check($u->id, agAt('2026-10-19 14:00'), agAt('2026-10-19 14:30'))->issues)->toContain('outside_hours')
        // 10:00 UTC = 05:00 en Bogotá: fuera aunque «10:00» parezca dentro.
        ->and($svc->check($u->id, Carbon::parse('2026-10-19 10:00', 'UTC'), Carbon::parse('2026-10-19 10:30', 'UTC'))->issues)->toContain('outside_hours')
        // El martes no tiene intervalos: día deshabilitado.
        ->and($svc->check($u->id, agAt('2026-10-20 10:00'), agAt('2026-10-20 10:30'))->issues)->toContain('outside_hours');

    AvailabilityException::query()->create(['user_id' => $u->id, 'date_from' => '2026-10-26', 'date_to' => '2026-10-30', 'kind' => 'unavailable', 'reason' => 'Vacaciones']);
    expect($svc->check($u->id, agAt('2026-10-26 10:00'), agAt('2026-10-26 10:30'))->issues)->toContain('absence');

    AvailabilityException::query()->create(['user_id' => $u->id, 'date_from' => '2026-10-25', 'date_to' => '2026-10-25', 'kind' => 'available', 'start_time' => '10:00:00', 'end_time' => '12:00:00']);
    expect($svc->check($u->id, agAt('2026-10-25 10:30'), agAt('2026-10-25 11:00'))->ok())->toBeTrue();

    // Se guarda en UTC y se muestra en la zona del asesor.
    $a = agCreate($u);
    expect(DB::table('crm_activities')->where('id', $a->id)->value('starts_at'))->toBe('2026-10-19 15:00:00')
        ->and($a->localStart()->format('H:i'))->toBe('10:00');
    Livewire::actingAs($u)->test(Index::class)->assertSee('10:00–10:30');
});

// 6 · Solapes ---------------------------------------------------------------------------------

it('detecta solapes del mismo asesor incluyendo márgenes; las canceladas no bloquean', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    $other = agUser($i);
    agProfile($u, ['buffer_after_minutes' => 15]);
    $first = agCreate($u); // 10:00–10:30

    $svc = app(AvailabilityService::class);
    expect($svc->check($u->id, agAt('2026-10-19 10:15'), agAt('2026-10-19 10:45'))->conflicts)->toHaveCount(1)
        ->and($svc->check($u->id, agAt('2026-10-19 10:40'), agAt('2026-10-19 11:00'))->conflicts)->toHaveCount(1) // dentro del margen
        ->and($svc->check($u->id, agAt('2026-10-19 10:45'), agAt('2026-10-19 11:15'))->ok())->toBeTrue()
        ->and($svc->check($other->id, agAt('2026-10-19 10:00'), agAt('2026-10-19 10:30'))->ok())->toBeTrue(); // otro asesor

    expect(fn () => agCreate($u, ['starts_at' => agAt('2026-10-19 10:20'), 'ends_at' => agAt('2026-10-19 10:50')]))
        ->toThrow(ScheduleConflictException::class);
    expect(Activity::query()->count())->toBe(1);

    app(ActivityService::class)->cancel($first, $u);
    expect($svc->check($u->id, agAt('2026-10-19 10:00'), agAt('2026-10-19 10:30'))->ok())->toBeTrue();
});

// 7 · Confirmación de excepción manual --------------------------------------------------------

it('fuera de disponibilidad avisa y no guarda; con confirmación guarda y registra quién la autorizó', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    agCreate($u); // 10:00–10:30

    $form = Livewire::actingAs($u)->test(Form::class)
        ->set('title', 'Encima')
        ->set('date', '2026-10-19')
        ->set('time', '10:15')
        ->set('duration', 30)
        ->assertSee('Este horario no está disponible') // aviso al mostrar
        ->call('save')
        ->assertHasErrors('confirmException');
    expect(Activity::query()->count())->toBe(1);

    $form->set('confirmException', true)->set('exceptionReason', 'Lo pidió el lead')->call('save')->assertHasNoErrors();
    $a = Activity::query()->where('title', 'Encima')->sole();
    expect($a->exception_by_user_id)->toBe($u->id)
        ->and($a->exception_at)->not->toBeNull()
        ->and($a->exception_reason)->toBe('Lo pidió el lead');
});

// 8 · Foto del enlace -------------------------------------------------------------------------

it('copia el enlace del perfil al crear y no cambia si luego cambia el perfil (cifrado en reposo)', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    $profile = agProfile($u, ['meet_url' => 'https://meet.google.com/aaa-bbbb-ccc']);
    $a = agCreate($u);

    $profile->update(['meet_url' => 'https://meet.google.com/zzz-yyyy-xxx']);
    $b = agCreate($u, ['starts_at' => agAt('2026-10-19 12:00'), 'ends_at' => agAt('2026-10-19 12:30')]);

    expect($a->fresh()->meeting_link)->toBe('https://meet.google.com/aaa-bbbb-ccc')
        ->and($b->meeting_link)->toBe('https://meet.google.com/zzz-yyyy-xxx')
        ->and(DB::table('crm_activities')->where('id', $a->id)->value('meeting_link'))->not->toContain('meet.google.com')
        ->and(DB::table('crm_meeting_profiles')->where('id', $profile->id)->value('meet_url'))->not->toContain('meet.google.com');
});

// 9 · Medios ----------------------------------------------------------------------------------

it('gestiona Meet, Zoom, WhatsApp, teléfono y presencial; valida el perfil', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u, [
        'zoom_url' => 'https://us02web.zoom.us/j/1234567890?pwd=abc',
        'whatsapp_number' => '+57 300 123 4567',
        'phone' => '+57 601 555 0000',
        'address' => 'Calle 1 # 2-3, Bogotá',
    ]);
    $slot = fn (int $h) => ['starts_at' => agAt("2026-10-19 {$h}:00"), 'ends_at' => agAt("2026-10-19 {$h}:30")];

    $meet = agCreate($u, $slot(8));
    $zoom = agCreate($u, ['medium' => 'zoom'] + $slot(9));
    $wa = agCreate($u, ['medium' => 'whatsapp', 'type' => 'call'] + $slot(10));
    $phone = agCreate($u, ['medium' => 'phone', 'type' => 'call'] + $slot(11));
    $inPerson = agCreate($u, ['medium' => 'in_person', 'type' => 'in_person_meeting'] + $slot(12));

    expect($meet->joinUrl())->toBe('https://meet.google.com/abc-defg-hij')
        ->and($zoom->joinUrl())->toBe('https://us02web.zoom.us/j/1234567890?pwd=abc')
        ->and($wa->joinUrl())->toStartWith('https://wa.me/573001234567?text=')
        ->and(urldecode((string) parse_url((string) $wa->joinUrl(), PHP_URL_QUERY)))->toContain('19/10/2026 10:00')
        ->and($phone->meeting_link)->toBe('+576015550000')
        ->and($phone->joinUrl())->toBeNull()
        ->and($inPerson->location)->toBe('Calle 1 # 2-3, Bogotá');

    $this->actingAs($u)->get(route('crm.agenda.show', $wa))->assertOk()->assertSee('Abrir WhatsApp');
    $this->actingAs($u)->get(route('crm.agenda.show', $zoom))->assertOk()->assertSee('Abrir Zoom');

    // Validación del perfil: solo HTTPS de participante y números internacionales.
    Livewire::actingAs($u)->test(MeetingProfileForm::class)
        ->set('meetUrl', 'http://meet.google.com/abc-defg-hij')
        ->set('zoomUrl', 'https://zoom.us/s/1234567890?zak=secreto')
        ->set('whatsappNumber', '3001234567')
        ->call('save')
        ->assertHasErrors(['meetUrl', 'zoomUrl', 'whatsappNumber']);
    Livewire::actingAs($u)->test(MeetingProfileForm::class)
        ->set('meetUrl', 'https://meet.google.com/new-meet-url')
        ->set('zoomUrl', 'https://zoom.us/j/999?pwd=x')
        ->set('whatsappNumber', '0057 300 123 4567')
        ->call('save')
        ->assertHasNoErrors();
    expect(MeetingProfile::query()->where('user_id', $u->id)->sole()->whatsapp_number)->toBe('+573001234567');
});

// 10 · Invitados existentes y externos --------------------------------------------------------

it('invita a un contacto existente o a un externo sin crear contactos ni leads', function () {
    Mail::fake();
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u);
    $contact = Contact::factory()->create(['preferred_language' => 'en']);
    [$contacts, $leads] = [Contact::query()->count(), Lead::query()->count()];

    $svc = app(ActivityService::class);
    $g1 = $svc->addGuest($a, $contact->id, null, null);
    $g2 = $svc->addGuest($a, null, 'Ana Externa', 'Ana.Externa@Example.org', 'es');

    expect($g1->contact_id)->toBe($contact->id)->and($g1->email)->toBe(strtolower((string) $contact->email))->and($g1->language)->toBe('en')
        ->and($g2->contact_id)->toBeNull()->and($g2->email)->toBe('ana.externa@example.org')
        ->and(Contact::query()->count())->toBe($contacts)
        ->and(Lead::query()->count())->toBe($leads);

    $this->actingAs($u)->get(route('crm.agenda.show', $a))->assertOk()
        ->assertSee('Ana Externa')->assertSee('Invitación enviada')->assertSee('Último correo');
});

// 11 · Sin invitados duplicados ---------------------------------------------------------------

it('no duplica invitados ni correos por doble clic o reintento; reactivar no crea otra fila', function () {
    Mail::fake();
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u);
    $svc = app(ActivityService::class);

    $svc->addGuest($a, null, 'Luis', 'luis@example.org');
    $svc->addGuest($a, null, 'Luis', 'LUIS@example.org ');
    Livewire::actingAs($u)->test(Show::class, ['activity' => $a])
        ->set('guestName', 'Luis')->set('guestEmail', 'luis@example.org')->call('addManualGuest');

    expect(ActivityGuest::query()->count())->toBe(1)
        ->and(agMails('luis@example.org', 'Invitación'))->toHaveCount(1);

    $guest = ActivityGuest::query()->sole();
    expect($svc->resendInvitation($guest))->toBeFalse(); // anti doble clic (60 s)

    $svc->removeGuest($guest);
    $svc->removeGuest($guest->fresh()); // reintento: una sola cancelación
    expect(agMails('luis@example.org', 'Cancelada'))->toHaveCount(1);

    $svc->addGuest($a, null, 'Luis', 'luis@example.org');
    expect(ActivityGuest::query()->count())->toBe(1)
        ->and(ActivityGuest::query()->sole()->removed_at)->toBeNull();
});

// 12 · Invitación inicial al añadir ------------------------------------------------------------

it('al añadir un invitado envía la invitación con .ics en su idioma', function () {
    Mail::fake();
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u, ['description' => 'Revisar el plan de estudios']);

    app(ActivityService::class)->addGuest($a, null, 'Jane', 'jane@example.org', 'en');

    $mail = agMails('jane@example.org', 'Invitation:')->sole();
    expect($mail->files)->toHaveCount(1)
        ->and($mail->files[0]['name'])->toBe('invitacion.ics')
        ->and($mail->files[0]['mime'])->toContain('method=REQUEST')
        ->and($mail->bodyHtml)->toContain('https://meet.google.com/abc-defg-hij')
        ->and($mail->bodyHtml)->toContain('America/Bogota')
        ->and($mail->bodyHtml)->toContain('Revisar el plan de estudios')
        ->and($mail->bodyHtml)->toContain(e($u->name));
    expect(ActivityGuest::query()->sole())->status->toBe('sent')->last_email_kind->toBe('invitation');
});

// 13 · Actualización al reprogramar -----------------------------------------------------------

it('reprogramar mantiene «programada», registra el cambio, sube la secuencia y avisa a los invitados activos', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u);
    $svc = app(ActivityService::class);
    $svc->addGuest($a, null, 'Activo', 'activo@example.org');
    $removed = $svc->addGuest($a, null, 'Quitado', 'quitado@example.org');
    $svc->removeGuest($removed);
    Mail::fake();

    Livewire::actingAs($u)->test(Show::class, ['activity' => $a])
        ->set('rsDate', '2026-10-19')->set('rsTime', '12:00')->set('rsDuration', 30)->set('rsReason', 'Cambio de agenda')
        ->call('reschedule');

    $a->refresh();
    expect($a->status)->toBe(ActivityStatus::Scheduled)
        ->and($a->localStart()->format('H:i'))->toBe('12:00')
        ->and($a->ics_sequence)->toBe(1)
        ->and($a->reschedule_count)->toBe(1)
        ->and(ActivityReschedule::query()->sole()->reason)->toBe('Cambio de agenda')
        ->and(agMails('activo@example.org', 'Actualizada'))->toHaveCount(1)
        ->and(agMails('quitado@example.org', 'Actualizada'))->toHaveCount(0)
        ->and(Event::query()->where('event_type', 'activity_rescheduled')->count())->toBe(0); // sin contacto vinculado
});

// 14 · Cancelación (quitar invitado o cancelar actividad) --------------------------------------

it('envía cancelación al quitar un invitado y a todos los activos al cancelar la actividad', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $lead = agLead($u);
    $a = agCreate($u, ['lead_id' => $lead->id]);
    $svc = app(ActivityService::class);
    $g1 = $svc->addGuest($a, null, 'Uno', 'uno@example.org');
    $svc->addGuest($a, null, 'Dos', 'dos@example.org');
    Mail::fake();

    $svc->removeGuest($g1);
    $mail = agMails('uno@example.org', 'Cancelada')->sole();
    expect($mail->files[0]['name'])->toBe('cancelacion.ics')->and($mail->files[0]['mime'])->toContain('method=CANCEL');

    $svc->cancel($a->fresh(), $u, 'Imprevisto');
    $svc->cancel($a->fresh(), $u, 'Imprevisto'); // reintento: sin efectos repetidos
    expect(agMails('dos@example.org', 'Cancelada'))->toHaveCount(1)
        ->and(agMails('uno@example.org', 'Cancelada'))->toHaveCount(1)
        ->and(Event::query()->where('event_type', 'activity_cancelled')->where('contact_id', $lead->contact_id)->count())->toBe(1)
        ->and($lead->fresh()->status->value)->toBe('new');
});

// 15 · .ics válido ----------------------------------------------------------------------------

it('genera un .ics válido (UID, UTC, SEQUENCE, ORGANIZER, ATTENDEE, VALARM, CANCEL)', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u, ['title' => 'Revisión; plan, fase 1', 'description' => str_repeat('Texto largo con acentos áéíóú. ', 8)]);
    $p = ['organizer_name' => 'Asesora Uno', 'organizer_email' => 'agenda@example.org', 'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.org', 'description' => (string) $a->description, 'url' => $a->meeting_link];

    $ics = app(IcsBuilder::class)->build($a, $p);
    $lines = explode("\r\n", rtrim($ics, "\r\n"));
    $unfolded = str_replace("\r\n ", '', $ics); // RFC 5545 §3.1: desplegado de líneas
    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0")
        ->and($ics)->toEndWith("END:VCALENDAR\r\n")
        ->and($unfolded)->toContain("METHOD:REQUEST\r\n")
        ->and($unfolded)->toContain('UID:'.$a->uid."\r\n")
        ->and($unfolded)->toContain("DTSTART:20261019T150000Z\r\n")
        ->and($unfolded)->toContain("DTEND:20261019T153000Z\r\n")
        ->and($unfolded)->toContain("SEQUENCE:0\r\n")
        ->and($unfolded)->toContain('SUMMARY:Revisión\; plan\, fase 1')
        ->and($unfolded)->toContain('ORGANIZER;CN="Asesora Uno":mailto:agenda@example.org')
        ->and($unfolded)->toContain('ATTENDEE;CN="Jane";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:jane@example.org')
        ->and($unfolded)->toContain("BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY")
        ->and($unfolded)->toContain('STATUS:CONFIRMED');
    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
    expect(mb_check_encoding($unfolded, 'UTF-8'))->toBeTrue()
        ->and(preg_match("/(?<!\r)\n/", $ics))->toBe(0); // solo CRLF

    app(ActivityService::class)->reschedule($a, agAt('2026-10-19 11:00'), agAt('2026-10-19 11:30'), $u);
    $a->refresh();
    $cancel = str_replace("\r\n ", '', app(IcsBuilder::class)->build($a, $p, cancel: true));
    expect($cancel)->toContain("METHOD:CANCEL\r\n")->toContain("STATUS:CANCELLED\r\n")
        ->toContain("SEQUENCE:1\r\n")->toContain('UID:'.$a->uid)->not->toContain('VALARM');
});

// 16 · Recordatorios idempotentes --------------------------------------------------------------

it('envía cada recordatorio una sola vez, nada si está cancelada y registra fallos y reintentos', function () {
    Mail::fake();
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u);
    $a = agCreate($u, ['reminders' => [60, 15]]); // 15:00 UTC
    app(ActivityService::class)->addGuest($a, null, 'Invitada', 'invitada@example.org');
    $cancelled = agCreate($u, ['reminders' => [60], 'starts_at' => agAt('2026-10-19 11:00'), 'ends_at' => agAt('2026-10-19 11:30')]);
    app(ActivityService::class)->cancel($cancelled, $u);

    expect(ActivityReminder::query()->where('activity_id', $a->id)->count())->toBe(6); // 2 desfases × (asesor correo + interno + invitada)

    $this->travelTo(Carbon::parse('2026-10-19 14:00:30', 'UTC'));
    $this->artisan('crm:agenda-reminders')->assertSuccessful();
    $this->artisan('crm:agenda-reminders')->assertSuccessful(); // segunda pasada: nada nuevo

    expect(agMails('invitada@example.org', 'Recordatorio'))->toHaveCount(1)
        ->and(agMails($u->email, 'Recordatorio'))->toHaveCount(1)
        ->and($u->notifications()->where('type', AgendaNotification::class)->count())->toBe(1)
        ->and(ActivityReminder::query()->where('activity_id', $a->id)->where('status', 'sent')->count())->toBe(3)
        ->and(ActivityReminder::query()->where('activity_id', $cancelled->id)->where('status', '!=', 'skipped')->count())->toBe(0);

    // Fallo de envío: reintento y, al agotar intentos, «failed» con el detalle solo interno.
    $c = agCreate($u, ['reminders' => [60], 'starts_at' => agAt('2026-10-19 13:00'), 'ends_at' => agAt('2026-10-19 13:30')]);
    $this->mock(AgendaMailer::class, function ($m): void {
        $m->shouldReceive('toAdvisor')->andReturn(false);
        $m->shouldReceive('toGuest')->andReturn(false);
        $m->shouldReceive('lastError')->andReturn('SMTP 554 rechazado');
    });
    $this->travelTo(Carbon::parse('2026-10-19 17:00:30', 'UTC'));
    foreach (range(1, 4) as $_) {
        $this->artisan('crm:agenda-reminders')->assertSuccessful();
    }
    $row = ActivityReminder::query()->where('activity_id', $c->id)->where('channel', 'mail')->sole();
    expect($row->status)->toBe('failed')->and($row->attempts)->toBe(3)->and($row->last_error)->toBe('SMTP 554 rechazado');
});

// 17 · Dashboard ------------------------------------------------------------------------------

it('el inicio muestra los 6 indicadores enlazados (propios del asesor) y «Mi agenda de hoy»', function () {
    $i = agInst();
    agCtx($i);
    $admin = agUser($i, 'admin', null);
    $advisor = agUser($i);
    $other = agUser($i);
    agProfile($advisor);
    agLead($advisor);
    agLead($other);
    agLead(null, 'qualified');
    $today = agCreate($advisor, ['title' => 'Demo de hoy']);
    agCreate($other, ['title' => 'Demo ajena']);
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    $overdue = agCreate($advisor, ['title' => 'Vencida', 'starts_at' => agAt('2026-10-19 01:00'), 'ends_at' => agAt('2026-10-19 01:30')], confirm: true);

    $adminView = Livewire::actingAs($admin)->test(Dashboard::class);
    foreach (['Nuevos sin contactar', 'Tareas vencidas', 'Agenda de hoy', 'Próximos 7 días', 'En seguimiento', 'Matriculados del mes'] as $label) {
        $adminView->assertSee($label);
    }
    $adminView->assertDontSee('Total de registros')->assertDontSee('Puntuación promedio')
        ->assertSee(route('crm.agenda.index', ['overdue' => 1]), false)
        ->assertSee(route('crm.leads.index', ['status' => 'enrolled', 'period' => 'month']));
    $k = $adminView->viewData('kpis');
    expect($k['new']['n'])->toBe(2)->and($k['followup']['n'])->toBe(1)->and($k['today']['n'])->toBe(3)->and($k['overdue']['n'])->toBe(1);

    $mine = Livewire::actingAs($advisor)->test(Dashboard::class);
    $k = $mine->viewData('kpis');
    expect($k['new']['n'])->toBe(1)->and($k['new']['url'])->toContain('mine=1')
        ->and($k['followup']['n'])->toBe(0)->and($k['today']['n'])->toBe(2)->and($k['next7']['n'])->toBe(1);
    $mine->assertSee('Mi agenda de hoy')->assertSee('Demo de hoy')->assertDontSee('Demo ajena')
        ->assertSee('https://meet.google.com/abc-defg-hij', false)
        ->assertSee(route('crm.agenda.show', ['activity' => $today->id, 'panel' => 'reschedule']), false)
        ->call('completeActivity', $today->id);
    expect($today->fresh()->status)->toBe(ActivityStatus::Completed)
        ->and($overdue->fresh()->exception_by_user_id)->toBe($advisor->id);

    // Un asesor no puede completar la actividad de otro desde el inicio.
    $foreign = Activity::query()->where('title', 'Demo ajena')->sole();
    Livewire::actingAs($advisor)->test(Dashboard::class)->call('completeActivity', $foreign->id)->assertForbidden();
});

// 18 · Sin selector de línea ------------------------------------------------------------------

it('el menú ya no muestra el selector «Microcredenciales · Línea activa» y ofrece Calendario en Captación', function () {
    $i = agInst();
    agCtx($i);
    $admin = agUser($i, 'admin', null);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Línea activa')
        ->assertDontSee('mca-switcher')
        ->assertSee(route('crm.agenda.index'), false)
        ->assertSeeInOrder(['CAPTACIÓN', 'Contactos', 'Calendario']);
});

// 19 · Acceso directo desde el calendario ------------------------------------------------------

it('cada actividad del calendario abre su detalle y muestra «Unirse»/«Abrir WhatsApp» solo con enlace aplicable', function () {
    $i = agInst();
    agCtx($i);
    $u = agUser($i);
    agProfile($u, ['whatsapp_number' => '+57 300 123 4567']);
    $slot = fn (int $h) => ['starts_at' => agAt("2026-10-19 {$h}:00"), 'ends_at' => agAt("2026-10-19 {$h}:30")];
    $meet = agCreate($u, ['title' => 'Con Meet'] + $slot(8));
    $wa = agCreate($u, ['title' => 'Con WhatsApp', 'medium' => 'whatsapp', 'type' => 'call'] + $slot(9));
    $phone = agCreate($u, ['title' => 'Por teléfono', 'medium' => 'phone', 'type' => 'call'] + $slot(10));
    $cancelled = agCreate($u, ['title' => 'Cancelada con Meet'] + $slot(11));
    app(ActivityService::class)->cancel($cancelled, $u);

    foreach (['week', 'list'] as $view) {
        $html = Livewire::actingAs($u)->test(Index::class, ['view' => $view])->html();
        foreach ([$meet, $wa, $phone, $cancelled] as $a) {
            expect($html)->toContain('href="'.route('crm.agenda.show', $a).'"'); // el detalle sigue abriéndose
        }
        expect($html)->toContain('href="https://meet.google.com/abc-defg-hij" target="_blank" rel="noopener noreferrer"')
            ->and(substr_count($html, 'https://meet.google.com/abc-defg-hij'))->toBe(1) // la cancelada no ofrece acceso
            ->and($html)->toContain(e((string) $wa->joinUrl()))
            ->and($html)->toContain('Abrir WhatsApp')
            ->and(substr_count($html, 'class="ag-join'))->toBe(2); // ni teléfono ni cancelada
    }
});
