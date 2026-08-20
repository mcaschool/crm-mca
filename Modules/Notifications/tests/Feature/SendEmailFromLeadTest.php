<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;

/**
 * Paso 3: enviar correo desde la ficha del lead con seleccion MANUAL del remitente,
 * y que quede en el historial de la persona (remitente, asunto, fecha, quien envio).
 */
function leadEmailCtx(string $role = 'admin', ?string $department = null): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $role, $department): array {
        $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role, 'department' => $department]);
        $contact = Contact::factory()->create(['email' => 'prospecto@example.com']);
        $lead = Lead::factory()->create(['contact_id' => $contact->id]);
        $sender = EmailSender::factory()->create(['name' => 'Finanzas MCA', 'from_address' => 'finanzas@mcaschool.education']);

        return [$institution, $user, $contact, $lead, $sender];
    });
}

it('envia el correo por el remitente ELEGIDO y lo registra en el historial', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = leadEmailCtx();

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->set('emailSenderId', (string) $sender->id)
        ->set('emailSubject', 'Información de tu programa')
        ->set('emailBody', "Hola,\nAdjunto la info.\nSaludos.")
        ->call('sendEmail')
        ->assertHasNoErrors()
        ->assertSet('composeOpen', false);

    // Salio por el SMTP del remitente elegido, al correo del contacto.
    Mail::assertSent(OutboundEmail::class, function (OutboundEmail $mail) use ($contact, $sender) {
        return $mail->hasTo($contact->email)
            && $mail->fromAddress === $sender->from_address
            && $mail->subjectLine === 'Información de tu programa';
    });

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($contact, $lead, $sender, $user) {
        $msg = EmailMessage::query()->first();
        expect($msg)->not->toBeNull();
        expect($msg->email_sender_id)->toBe($sender->id);
        expect($msg->contact_id)->toBe($contact->id);
        expect($msg->lead_id)->toBe($lead->id);
        expect($msg->sent_by_user_id)->toBe($user->id); // quien del equipo lo envio
        expect($msg->from_address)->toBe('finanzas@mcaschool.education');
        expect($msg->subject)->toBe('Información de tu programa');
        expect($msg->status)->toBe('sent');

        // Deja rastro en el timeline con el asunto.
        $event = Event::query()->where('event_type', 'email_sent')->first();
        expect($event)->not->toBeNull();
        expect($event->detail())->toBe('Información de tu programa');
    });
});

it('exige elegir remitente, asunto y cuerpo (el sistema no elige solo)', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = leadEmailCtx();

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->set('emailSenderId', '')
        ->set('emailSubject', '')
        ->set('emailBody', '')
        ->call('sendEmail')
        ->assertHasErrors(['emailSenderId', 'emailSubject', 'emailBody']);

    Mail::assertNothingSent();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(EmailMessage::query()->count())->toBe(0));
});

it('Marketing NO puede enviar correo', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = leadEmailCtx('marketing');

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->assertForbidden();
});

it('Admisiones SÍ puede enviar correo (rol operativo)', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = leadEmailCtx('admissions');

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->set('emailSenderId', (string) $sender->id)
        ->set('emailSubject', 'Seguimiento')
        ->set('emailBody', 'Hola, seguimos en contacto.')
        ->call('sendEmail')
        ->assertHasNoErrors()
        ->assertSet('composeOpen', false);

    Mail::assertSent(OutboundEmail::class);
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(EmailMessage::query()->where('status', 'sent')->count())->toBe(1));
});

it('la política de envío incluye a los 5 (Admin, Admisiones, Académico, Finanzas, Soporte) y excluye a Marketing', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution) {
        $make = fn (string $role, ?string $dept = null) => User::factory()->create([
            'institution_id' => $institution->id, 'role' => $role, 'department' => $dept,
        ]);

        expect($make('admin')->canSendEmail())->toBeTrue();
        expect($make('admissions')->canSendEmail())->toBeTrue();                          // Admisiones
        expect($make('admissions', 'Académico')->canSendEmail())->toBeTrue();             // Académico
        expect($make('admissions', 'Contabilidad y Finanzas')->canSendEmail())->toBeTrue(); // Finanzas
        expect($make('admissions', 'Soporte')->canSendEmail())->toBeTrue();               // Soporte
        expect($make('marketing')->canSendEmail())->toBeFalse();                          // Marketing NO
    });
});
