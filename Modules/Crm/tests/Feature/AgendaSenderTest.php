<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\ActivityReminder;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Livewire\EmailSenders\Manage;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\SenderMailer;

/*
| Remitente de la Agenda: siempre uno válido de la PROPIA institución (marcado como
| «Agenda» o, sin marca, el único activo). Nunca otro remitente, otra institución ni el
| correo del sistema. Fuera de producción sale por el transporte «log» (sin SMTP real).
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    Mail::fake();
});

/** @return array{0:Institution,1:User} institución activa + asesor */
function snInst(): array
{
    $i = Institution::factory()->create();
    app(CurrentInstitution::class)->set($i->id);

    return [$i, User::factory()->create(['institution_id' => $i->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active'])];
}

function snAdmin(Institution $i): User
{
    return User::factory()->create(['institution_id' => $i->id, 'role' => 'admin', 'status' => 'active']);
}

/** @param  array<int,int>  $reminders */
function snActivity(User $u, array $reminders = []): Activity
{
    return app(ActivityService::class)->create([
        'title' => 'Orientación', 'type' => 'video_call', 'medium' => 'none',
        'starts_at' => Carbon::parse('2026-10-19 15:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-19 15:30', 'UTC'),
        'timezone' => 'UTC', 'assigned_to_user_id' => $u->id, 'reminders' => $reminders,
    ], $u);
}

it('usa el remitente marcado para la Agenda aunque haya varios activos (y por transporte seguro)', function () {
    [$i, $u] = snInst();
    EmailSender::factory()->create(['name' => 'A primero', 'from_address' => 'general@mcaschool.education']);
    $agenda = EmailSender::factory()->create(['name' => 'Z agenda', 'from_address' => 'agenda@mcaschool.education']);

    Livewire::actingAs(snAdmin($i))->test(Manage::class)->call('useForAgenda', $agenda->id)->assertHasNoErrors();
    expect(EmailSender::query()->where('agenda_default', true)->pluck('id')->all())->toBe([$agenda->id]);

    app(ActivityService::class)->addGuest(snActivity($u), null, 'Ana', 'ana@example.org');

    Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo('ana@example.org')
        && $m->fromAddress === 'agenda@mcaschool.education' && $m->mailer === 'log');
    Mail::assertNotSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->fromAddress === 'general@mcaschool.education');
});

it('sin marca: usa el único activo; con varios sin marca o con la marca inactiva no envía y permite reintentar', function () {
    [$i, $u] = snInst();
    $only = EmailSender::factory()->create(['from_address' => 'unico@mcaschool.education']);
    $svc = app(ActivityService::class);
    $svc->addGuest(snActivity($u), null, 'Uno', 'uno@example.org');
    Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo('uno@example.org') && $m->fromAddress === 'unico@mcaschool.education');

    // Varios activos sin marca: no se adivina.
    EmailSender::factory()->create(['from_address' => 'otro@mcaschool.education']);
    $a = $svc->create([
        'title' => 'Segunda', 'type' => 'call', 'medium' => 'none',
        'starts_at' => Carbon::parse('2026-10-19 17:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-19 17:30', 'UTC'),
        'timezone' => 'UTC', 'assigned_to_user_id' => $u->id, 'reminders' => [60],
    ], $u);
    $guest = $svc->addGuest($a, null, 'Dos', 'dos@example.org');
    expect($guest->fresh()->status)->toBe('failed')->and($guest->fresh()->last_emailed_at)->toBeNull();
    Mail::assertNotSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo('dos@example.org'));

    // El recordatorio también queda pendiente de reintento, con el motivo solo interno.
    $this->travelTo(Carbon::parse('2026-10-19 16:00:30', 'UTC'));
    $this->artisan('crm:agenda-reminders')->assertSuccessful();
    $row = ActivityReminder::query()->where('activity_id', $a->id)->where('recipient_type', 'guest')->sole();
    expect($row->status)->toBe('pending')->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBe('Hay varios remitentes activos y ninguno está marcado para el Calendario.');

    // Se marca uno explícitamente → el reintento sale por él.
    Livewire::actingAs(snAdmin($i))->test(Manage::class)->call('useForAgenda', $only->id);
    expect($svc->resendInvitation($guest->fresh()))->toBeTrue();
    Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo('dos@example.org') && $m->fromAddress === 'unico@mcaschool.education');

    // Marca sobre un remitente que pasa a inactivo: no cae en el otro activo.
    $only->forceFill(['status' => 'inactive'])->save();
    $svc->addGuest($a, null, 'Tres', 'tres@example.org');
    Mail::assertNotSent(OutboundEmail::class, fn (OutboundEmail $m) => $m->hasTo('tres@example.org'));
    expect(ActivityGuest::query()->where('email', 'tres@example.org')->sole()->status)->toBe('failed');
});

it('nunca usa el remitente de otra institución ni deja marcarlo desde otra', function () {
    [$b, $ub] = snInst();
    $foreign = EmailSender::factory()->create(['from_address' => 'agenda-b@mcaschool.education']);
    $foreign->forceFill(['agenda_default' => true])->save();

    [$a, $ua] = snInst(); // institución A activa, sin remitentes propios
    app(ActivityService::class)->addGuest(snActivity($ua), null, 'Ana', 'ana@example.org');
    Mail::assertNothingSent();
    expect(ActivityGuest::query()->sole()->status)->toBe('failed');

    // El scope de institución no encuentra el remitente ajeno (404 en el panel).
    expect(fn () => Livewire::actingAs(snAdmin($a))->test(Manage::class)->call('useForAgenda', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh()->agenda_default)->toBeTrue();
});

it('en producción sale por el SMTP del remitente de la Agenda (SenderMailer), sin tocar SMTP real en la prueba', function () {
    [$i, $u] = snInst();
    $agenda = EmailSender::factory()->create(['from_address' => 'agenda@mcaschool.education']);
    config(['crm.agenda.sender_smtp' => true]);
    $this->mock(SenderMailer::class, function ($m) use ($agenda): void {
        $m->shouldReceive('send')->once()->withArgs(fn (EmailSender $s, string $to, string $subject) => $s->is($agenda)
            && $to === 'ana@example.org' && str_starts_with($subject, 'Invitación'));
    });

    $guest = app(ActivityService::class)->addGuest(snActivity($u), null, 'Ana', 'ana@example.org');
    expect($guest->fresh()->status)->toBe('sent');
    Mail::assertNothingSent();
});
