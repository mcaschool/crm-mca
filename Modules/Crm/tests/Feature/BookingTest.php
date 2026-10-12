<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Agenda\BookingSettings;
use Modules\Crm\Livewire\Agenda\CalendarTemplatesManage;
use Modules\Crm\Livewire\Agenda\LeadActivities;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\AvailabilityException;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\Booking;
use Modules\Crm\Models\CalendarTemplate;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;
use Modules\Crm\Notifications\AgendaNotification;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Booking\CalendarTemplates;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;

/*
| Reservas públicas (Fase 3). Lunes 19/10/2026 07:00 UTC; el asesor trabaja en America/Bogota
| (UTC−5) de lunes a viernes de 09:00 a 12:00 → 14:00–17:00 UTC. El visitante, en Europe/Madrid.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    Mail::fake();
});

/** @return array{inst:Institution,advisor:User,profile:MeetingProfile,type:MeetingType} */
function bk(array $profile = [], bool $sender = true, string $name = 'Ana García', string $slug = 'ana'): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    if ($sender) {
        EmailSender::factory()->create();
    }
    Bot::factory()->create();
    $advisor = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active', 'name' => $name]);

    return ['inst' => $inst, 'advisor' => $advisor] + bkAdvisor($advisor, $profile, $slug);
}

/** @return array{profile:MeetingProfile,type:MeetingType} */
function bkAdvisor(User $advisor, array $profile = [], string $slug = 'ana'): array
{
    $p = MeetingProfile::query()->create(array_merge([
        'user_id' => $advisor->id, 'timezone' => 'America/Bogota', 'meet_url' => 'https://meet.google.com/abc-defg-hij',
        'booking_enabled' => true, 'booking_slug' => $slug,
    ], $profile));
    foreach ([1, 2, 3, 4, 5] as $wd) {
        AvailabilityRule::query()->create(['user_id' => $advisor->id, 'weekday' => $wd, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
    }
    $type = MeetingType::query()->create(['user_id' => $advisor->id, 'name' => 'Consulta de admisiones', 'slug' => 'consulta', 'duration_minutes' => 30, 'medium' => 'google_meet', 'is_public' => true]);

    return ['profile' => $p, 'type' => $type];
}

/** @param  array<string,mixed>  $over */
function bkPost(array $s, array $over = []): \Illuminate\Testing\TestResponse
{
    $data = array_merge([
        'start' => '2026-10-19T15:00:00Z', 'timezone' => 'Europe/Madrid', 'first_name' => 'Laura', 'last_name' => 'Pérez',
        'email' => 'laura@example.org', 'idempotency_key' => (string) Str::uuid(),
        'form_token' => Crypt::encryptString((string) (now()->getTimestamp() - 10)), 'website' => '', 'lang' => 'es',
    ], $over);

    return test()->post(route('booking.store', [$s['inst']->slug, $s['profile']->booking_slug, $s['type']->slug]), $data);
}

function bkToken(\Illuminate\Testing\TestResponse $r): string
{
    preg_match('~/reservar/gestion/([A-Za-z0-9]{48})~', (string) $r->headers->get('Location'), $m);

    return $m[1] ?? '';
}

/** @return \Illuminate\Support\Collection<int,OutboundEmail> */
function bkMails(string $to, string $prefix): \Illuminate\Support\Collection
{
    return Mail::sent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo($to) && str_starts_with($m->subjectLine, $prefix));
}

// 1 · Creación y publicación de enlaces ---------------------------------------------------------

it('publica el enlace del asesor y de cada tipo con alias legibles, y oculta lo no publicable', function () {
    $s = bk();
    $hidden = MeetingType::query()->create(['user_id' => $s['advisor']->id, 'name' => 'Privada', 'slug' => 'privada', 'medium' => 'google_meet', 'is_public' => false]);
    $url = route('booking.advisor', [$s['inst']->slug, 'ana']);
    expect($url)->toEndWith('/reservar/'.$s['inst']->slug.'/ana')->not->toContain((string) $s['advisor']->id);

    $this->get($url)->assertOk()->assertSee('Ana García')->assertSee('Consulta de admisiones')->assertDontSee('Privada');
    $this->get(route('booking.type', [$s['inst']->slug, 'ana', 'consulta']))->assertOk()
        ->assertSee('Consulta de admisiones')->assertDontSee('meet.google.com'); // el enlace solo tras reservar
    $this->get(route('booking.type', [$s['inst']->slug, 'ana', 'privada']))->assertNotFound();
    $this->get(route('booking.type', [$s['inst']->slug, 'ana', 'consulta']).'?lang=en')->assertOk()
        ->assertSee('Choose a day and a time')->assertSee('Confirm booking');

    Livewire::actingAs($s['advisor'])->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])
        ->assertSee($url)->set('bookingEnabled', false)->call('saveLink')->assertHasNoErrors();
    $this->get($url)->assertNotFound();

    // Sin horario no se pueden activar las reservas.
    AvailabilityRule::query()->where('user_id', $s['advisor']->id)->delete();
    Livewire::actingAs($s['advisor'])->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])
        ->set('bookingEnabled', true)->call('saveLink')->assertHasErrors('bookingEnabled');
    expect($hidden->exists)->toBeTrue();
});

// 2 · Tipos de reunión y permisos ---------------------------------------------------------------

it('gestiona tipos de reunión: exige el dato de conexión para publicar y respeta los permisos', function () {
    $s = bk();
    Livewire::actingAs($s['advisor'])->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])
        ->call('newType')->set('name', 'Orientación académica')->set('medium', 'zoom')->set('duration', 45)->set('typePublic', true)
        ->call('saveType')->assertHasErrors('typePublic') // sin enlace de Zoom en el perfil
        ->set('typePublic', false)->call('saveType')->assertHasNoErrors();
    $t = MeetingType::query()->where('slug', 'orientacion-academica')->sole();
    expect($t->is_public)->toBeFalse()->and($t->duration_minutes)->toBe(45);

    $s['profile']->update(['zoom_url' => 'https://zoom.us/j/123456789?pwd=x']);
    Livewire::actingAs($s['advisor'])->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])->call('toggleType', $t->id, 'is_public');
    expect($t->fresh()->is_public)->toBeTrue();

    $other = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    Livewire::actingAs($other)->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])->assertForbidden();
    $admin = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'admin', 'status' => 'active']);
    Livewire::actingAs($admin)->test(BookingSettings::class, ['ownerId' => $s['advisor']->id])->assertOk()->assertSee('Orientación académica');
    $readOnly = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'marketing', 'department' => 'Marketing', 'status' => 'active']);
    Livewire::actingAs($readOnly)->test(BookingSettings::class, ['ownerId' => $readOnly->id])->assertForbidden();
});

// 3 · Disponibilidad y zonas horarias ----------------------------------------------------------

it('ofrece huecos reales en la zona del visitante (con horario de verano) y respeta bloqueos', function () {
    $s = bk(['buffer_after_minutes' => 15]);
    app(ActivityService::class)->create([
        'title' => 'Interna', 'type' => 'call', 'medium' => 'none', 'timezone' => 'America/Bogota', 'assigned_to_user_id' => $s['advisor']->id,
        'starts_at' => Carbon::parse('2026-10-19 15:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-19 15:30', 'UTC'), 'reminders' => [],
    ], $s['advisor']);
    AvailabilityException::query()->create(['user_id' => $s['advisor']->id, 'date_from' => '2026-10-21', 'date_to' => '2026-10-21', 'kind' => 'unavailable']);

    $days = $this->getJson(route('booking.slots', [$s['inst']->slug, 'ana', 'consulta']).'?month=2026-10&tz=Europe/Madrid')->assertOk()->json('days');
    $mon = collect($days['2026-10-19'])->pluck('time')->all();
    expect($mon)->toBe(['16:00', '18:00', '18:30']) // 09:00 Bogotá = 16:00 Madrid (CEST); 10:00–10:30 ocupado + margen
        ->and($days)->not->toHaveKey('2026-10-21')   // ausencia
        ->and($days)->not->toHaveKey('2026-10-24')   // sábado
        ->and(collect($days['2026-10-26'])->pluck('time')->first())->toBe('15:00'); // tras el cambio de hora (CET)
    expect($days['2026-10-19'][0]['start'])->toBe('2026-10-19T14:00:00Z');
});

// 4 · Reserva pública y solapamientos ----------------------------------------------------------

it('reserva un hueco, crea la actividad del asesor y no permite ocuparlo dos veces', function () {
    $s = bk();
    $r = bkPost($s)->assertRedirect();
    $token = bkToken($r);
    $a = Activity::query()->sole();
    expect($a->assigned_to_user_id)->toBe($s['advisor']->id)
        ->and($a->status->value)->toBe('scheduled')
        ->and($a->starts_at->format('Y-m-d H:i'))->toBe('2026-10-19 15:00')
        ->and($a->meeting_link)->toBe('https://meet.google.com/abc-defg-hij')
        ->and($a->exception_at)->toBeNull()
        ->and($a->guests()->sole()->email)->toBe('laura@example.org');

    $this->get(route('booking.manage', ['token' => $token]))->assertOk()->assertSee('https://meet.google.com/abc-defg-hij')->assertSee('Confirmada');

    bkPost($s, ['email' => 'otra@example.org'])->assertSessionHasErrors('start');
    bkPost($s, ['start' => '2026-10-19T19:00:00Z'])->assertSessionHasErrors('start'); // fuera de horario
    bkPost($s, ['start' => '2026-10-19T15:10:00Z'])->assertSessionHasErrors('start'); // fuera de la rejilla
    expect(Activity::query()->count())->toBe(1);
});

// 5 · Idempotencia y concurrencia ---------------------------------------------------------------

it('es idempotente ante reintentos y aplica la política conservadora de sala compartida', function () {
    $s = bk();
    $key = (string) Str::uuid();
    $t1 = bkToken(bkPost($s, ['idempotency_key' => $key]));
    $t2 = bkToken(bkPost($s, ['idempotency_key' => $key]));
    expect($t1)->toBe($t2)
        ->and(Activity::query()->count())->toBe(1)
        ->and(Booking::query()->count())->toBe(1)
        ->and(bkMails('laura@example.org', 'Confirmada'))->toHaveCount(1);

    // Otro asesor con la MISMA sala de Meet no puede ofrecer ni reservar ese mismo horario.
    $other = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    $o = bkAdvisor($other, [], 'otro');
    $days = $this->getJson(route('booking.slots', [$s['inst']->slug, 'otro', 'consulta']).'?month=2026-10&tz=UTC')->json('days');
    expect(collect($days['2026-10-19'])->pluck('start'))->not->toContain('2026-10-19T15:00:00Z');
    bkPost(['inst' => $s['inst']] + $o, ['email' => 'x@example.org'])->assertSessionHasErrors('start');
});

// 6 · Asociación o creación de leads y contactos -----------------------------------------------

it('crea contacto y lead (booking_link) o reutiliza los existentes sin sobrescribir ni cambiar la etapa', function () {
    $s = bk();
    bkPost($s, ['email' => 'Nueva@Example.org', 'first_name' => 'Nueva', 'last_name' => 'Persona']);
    $c = Contact::query()->where('email', 'nueva@example.org')->sole();
    $lead = Lead::query()->where('contact_id', $c->id)->sole();
    expect($lead->source)->toBe('booking_link')->and($lead->assigned_to_user_id)->toBe($s['advisor']->id)
        ->and(Activity::query()->sole()->lead_id)->toBe($lead->id);

    $existing = Contact::factory()->create(['email' => 'cliente@example.org', 'first_name' => 'Nombre Real', 'last_name' => 'Apellido']);
    $active = Lead::factory()->create(['contact_id' => $existing->id, 'bot_id' => Bot::query()->value('id'), 'status' => 'qualified']);
    bkPost($s, ['email' => 'cliente@example.org', 'first_name' => 'X', 'last_name' => 'Y', 'start' => '2026-10-19T16:00:00Z']);
    $a = Activity::query()->latest('id')->first();
    expect($a->lead_id)->toBe($active->id)->and($a->contact_id)->toBe($existing->id)
        ->and($existing->fresh()->first_name)->toBe('Nombre Real')
        ->and($active->fresh()->status->value)->toBe('qualified')
        ->and(Lead::query()->where('contact_id', $existing->id)->count())->toBe(1);

    // Dos leads activos sin uno asignado a este asesor: solo contacto (ambiguo, no se elige).
    $two = Contact::factory()->create(['email' => 'dos@example.org']);
    Lead::factory()->count(2)->create(['contact_id' => $two->id, 'bot_id' => Bot::query()->value('id'), 'status' => 'new']);
    bkPost($s, ['email' => 'dos@example.org', 'start' => '2026-10-19T16:30:00Z']);
    expect(Activity::query()->latest('id')->first())->lead_id->toBeNull()->contact_id->toBe($two->id);

    // Enlace contextual desde la ficha del lead: la reserva queda vinculada a ese lead.
    $target = Lead::factory()->create(['contact_id' => Contact::factory()->create()->id, 'bot_id' => Bot::query()->value('id')]);
    $lc = Livewire::actingAs($s['advisor'])->test(LeadActivities::class, ['leadId' => $target->id])->call('generateBookingLink');
    $ref = Str::after($lc->get('bookingUrl'), '?ref=');
    expect($lc->get('bookingUrl'))->toMatch('~/reservar/'.preg_quote($s['inst']->slug, '~').'/ana\?ref=[A-Za-z0-9]{48}$~'); // sin id del lead
    bkPost($s, ['email' => 'quien@example.org', 'ref' => $ref, 'start' => '2026-10-20T14:00:00Z']);
    expect(Activity::query()->latest('id')->first()->lead_id)->toBe($target->id);
});

// 7 · Aislamiento institucional ----------------------------------------------------------------

it('aísla instituciones: alias, contactos, enlaces contextuales y tokens', function () {
    $a = bk();
    $foreignContact = Contact::factory()->create(['email' => 'shared@example.org']); // institución A
    $b = bk([], true, 'Bruno', 'bruno');

    $this->get(route('booking.advisor', [$b['inst']->slug, 'ana']))->assertNotFound(); // alias de A bajo B
    bkPost($b, ['email' => 'shared@example.org']);
    app(CurrentInstitution::class)->set($b['inst']->id);
    $act = Activity::query()->sole();
    expect($act->contact_id)->not->toBe($foreignContact->id)
        ->and(Contact::query()->where('email', 'shared@example.org')->count())->toBe(1); // una en B
    app(CurrentInstitution::class)->set($a['inst']->id);
    expect(Activity::query()->count())->toBe(0)->and(Contact::query()->where('email', 'shared@example.org')->sole()->id)->toBe($foreignContact->id);
});

// 8 · Confirmaciones y notificaciones sin duplicados ---------------------------------------------

it('confirma al visitante y avisa al asesor una sola vez; los fallos se reintentan sin duplicar', function () {
    $s = bk();
    bkPost($s);
    $mail = bkMails('laura@example.org', 'Confirmada')->sole();
    expect($mail->files[0]['name'])->toBe('invitacion.ics')
        ->and($mail->bodyHtml)->toContain('calendar.google.com')->toContain('outlook.office.com')->toContain('/reservar/gestion/')
        ->and(bkMails($s['advisor']->email, 'Nueva reserva'))->toHaveCount(1)
        ->and($s['advisor']->notifications()->where('type', AgendaNotification::class)->count())->toBe(1);

    $this->artisan('crm:agenda-reminders')->assertSuccessful();
    expect(bkMails('laura@example.org', 'Confirmada'))->toHaveCount(1)->and(bkMails($s['advisor']->email, 'Nueva reserva'))->toHaveCount(1);

    // Sin remitente: la reserva existe una sola vez, el correo queda pendiente y se reintenta luego.
    $n = bk([], false, 'Nora', 'nora');
    bkPost($n, ['email' => 'nora.v@example.org']);
    $booking = Booking::query()->sole();
    expect($booking->confirmation_sent_at)->toBeNull()->and($booking->mail_attempts)->toBe(1)->and(bkMails('nora.v@example.org', 'Confirmada'))->toHaveCount(0);
    EmailSender::factory()->create();
    $this->travel(2)->minutes();
    $this->artisan('crm:agenda-reminders')->assertSuccessful();
    $this->artisan('crm:agenda-reminders')->assertSuccessful();
    expect(bkMails('nora.v@example.org', 'Confirmada'))->toHaveCount(1)->and(Booking::query()->count())->toBe(1)->and(Activity::query()->count())->toBe(1);
});

// 9 · Cancelación y reprogramación mediante tokens ----------------------------------------------

it('el visitante reprograma y cancela con su token; liberado el hueco y sin operaciones posteriores', function () {
    $s = bk();
    $token = bkToken(bkPost($s));
    $a = Activity::query()->sole();

    $this->get(route('booking.reschedule', ['token' => $token]))->assertOk();
    $this->post(route('booking.reschedule.store', ['token' => $token]), [
        'start' => '2026-10-20T14:30:00Z', 'form_token' => Crypt::encryptString((string) (now()->getTimestamp() - 10)),
    ])->assertRedirect();
    $a->refresh();
    expect($a->starts_at->format('Y-m-d H:i'))->toBe('2026-10-20 14:30')->and($a->status->value)->toBe('scheduled')
        ->and($a->ics_sequence)->toBe(1)->and($a->reschedules()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and(bkMails('laura@example.org', 'Reprogramada'))->toHaveCount(1)
        ->and($s['advisor']->notifications()->get()->pluck('data.kind')->all())->toContain('booking_rescheduled');

    $this->post(route('booking.cancel', ['token' => $token]))->assertRedirect();
    expect($a->fresh()->status->value)->toBe('cancelled')->and(bkMails('laura@example.org', 'Cancelada'))->toHaveCount(1);
    $slots = $this->getJson(route('booking.slots', [$s['inst']->slug, 'ana', 'consulta']).'?month=2026-10&tz=UTC')->json('days.2026-10-20');
    expect(collect($slots)->pluck('start'))->toContain('2026-10-20T14:30:00Z'); // vuelve a estar libre

    $this->get(route('booking.reschedule', ['token' => $token]))->assertStatus(410);
    $this->post(route('booking.cancel', ['token' => $token]))->assertRedirect();
    expect(bkMails('laura@example.org', 'Cancelada'))->toHaveCount(1);
    $this->get(route('booking.manage', ['token' => Str::random(48)]))->assertNotFound();
});

// 10 · .ics y enlaces de calendarios -----------------------------------------------------------

it('genera el .ics con UID estable y enlaces para Google y Outlook', function () {
    $s = bk();
    $token = bkToken(bkPost($s));
    $a = Activity::query()->sole();
    $ics = $this->get(route('booking.ics', ['token' => $token]))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();
    $ics = str_replace("\r\n ", '', (string) $ics);
    expect($ics)->toContain('UID:'.$a->uid)->toContain('DTSTART:20261019T150000Z')->toContain('METHOD:REQUEST')
        ->toContain('meet.google.com/abc-defg-hij')->toContain('TRIGGER:-PT15M');

    $this->get(route('booking.manage', ['token' => $token]))->assertOk()
        ->assertSee('calendar.google.com/calendar/render?action=TEMPLATE', false)
        ->assertSee('dates=20261019T150000Z%2F20261019T153000Z', false)
        ->assertSee('outlook.office.com/calendar/0/deeplink/compose', false)
        ->assertSee(route('booking.ics', ['token' => $token]), false);
});

// 11 · Plantillas: personalización y seguridad --------------------------------------------------

it('personaliza las plantillas con variables autorizadas, sin HTML activo y solo para administración', function () {
    $s = bk();
    $admin = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'admin', 'status' => 'active']);
    Livewire::actingAs($s['advisor'])->test(CalendarTemplatesManage::class)->assertForbidden();

    Livewire::actingAs($admin)->test(CalendarTemplatesManage::class)
        ->set('subject', 'Reserva {{ tipo_reunion }}')->set('body', 'Hola {{nombre}} {{ config }}')->call('save')->assertHasErrors('body')
        ->set('body', "Hola {{nombre}} <script>alert(1)</script>\nAcceso: {{enlace}}")->call('save')->assertHasNoErrors();
    expect(CalendarTemplate::query()->where('key', 'booking_confirmation')->where('locale', 'es')->count())->toBe(1);

    bkPost($s, ['first_name' => '<b>Eva</b>']);
    $mail = bkMails('laura@example.org', 'Reserva Consulta de admisiones')->sole();
    expect($mail->bodyHtml)->toContain('&lt;script&gt;')->not->toContain('<script>')
        ->toContain('&lt;b&gt;Eva&lt;/b&gt;')
        ->toContain('<a href="https://meet.google.com/abc-defg-hij"');

    Livewire::actingAs($admin)->test(CalendarTemplatesManage::class)->call('restore');
    expect(CalendarTemplate::query()->count())->toBe(0)
        ->and(app(CalendarTemplates::class)->get('booking_confirmation', 'en')['subject'])->toStartWith('Confirmed:');
});

// 12 · Protección contra solicitudes abusivas ---------------------------------------------------

it('rechaza automatismos (campo trampa, envío instantáneo) y limita la frecuencia por IP y correo', function () {
    $s = bk();
    bkPost($s, ['website' => 'http://spam.example'])->assertSessionHasErrors('start');
    bkPost($s, ['form_token' => Crypt::encryptString((string) now()->getTimestamp())])->assertSessionHasErrors('start');
    bkPost($s, ['form_token' => 'manipulado'])->assertSessionHasErrors('start');
    expect(Booking::query()->count())->toBe(0);

    $hours = ['14:00', '14:30', '15:00', '15:30', '16:00'];
    foreach ($hours as $h) {
        bkPost($s, ['start' => '2026-10-20T'.$h.':00Z', 'email' => 'mucho@example.org']);
    }
    bkPost($s, ['start' => '2026-10-20T16:30:00Z', 'email' => 'mucho@example.org'])->assertSessionHasErrors('email');
    expect(Booking::query()->count())->toBe(5);

    $last = null;
    foreach (range(1, 6) as $i) {
        $last = bkPost($s, ['start' => '2026-10-21T14:00:00Z', 'email' => "ip{$i}@example.org"]);
    }
    $last?->assertStatus(429);
});
