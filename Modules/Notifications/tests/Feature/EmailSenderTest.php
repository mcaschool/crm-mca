<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Livewire\EmailSenders\Manage;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;

/**
 * Remitentes de correo (Paso 2): CRUD + cifrado/enmascarado de credenciales SMTP +
 * "Probar envio". Solo Admin.
 */
function mailAdmin(string $role = 'admin'): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    return $user;
}

const SMTP_PASS = 'smtp-secreto-larguisimo-98765';

it('registra un remitente con las credenciales SMTP CIFRADAS y enmascaradas', function () {
    mailAdmin();

    Livewire::test(Manage::class)
        ->call('newSender')
        ->set('name', 'Finanzas MCA')
        ->set('from_address', 'finanzas@mcaschool.education')
        ->set('host', 'smtp.hostinger.com')
        ->set('port', '465')
        ->set('username', 'finanzas@mcaschool.education')
        ->set('password', SMTP_PASS)
        ->set('encryption', 'ssl')
        ->call('save')
        ->assertHasNoErrors();

    $sender = EmailSender::query()->where('from_address', 'finanzas@mcaschool.education')->first();
    expect($sender)->not->toBeNull();
    expect($sender->name)->toBe('Finanzas MCA');
    expect($sender->secret('password'))->toBe(SMTP_PASS);
    expect($sender->secret('host'))->toBe('smtp.hostinger.com');

    // En disco la config NO contiene la contraseña en claro (columna cifrada).
    $raw = DB::table('email_senders')->where('id', $sender->id)->value('config');
    expect($raw)->not->toContain(SMTP_PASS);

    // El preview (unica version mostrable) enmascara la contraseña; host en claro.
    expect($sender->maskedConfig()['password'])->not->toContain(SMTP_PASS);
    expect($sender->maskedConfig()['password'])->toContain('••••');
    expect($sender->maskedConfig()['host'])->toBe('smtp.hostinger.com');
});

it('rechaza una direccion que no sea del dominio institucional', function () {
    mailAdmin();

    Livewire::test(Manage::class)
        ->call('newSender')
        ->set('name', 'Externo')
        ->set('from_address', 'alguien@gmail.com')
        ->set('host', 'smtp.hostinger.com')->set('port', '465')
        ->set('username', 'x')->set('password', SMTP_PASS)->set('encryption', 'ssl')
        ->call('save')
        ->assertHasErrors('from_address');

    expect(EmailSender::query()->count())->toBe(0);
});

it('al editar, la contrasena en blanco CONSERVA la actual', function () {
    mailAdmin();
    $sender = EmailSender::factory()->create(['name' => 'Academics']);
    $original = $sender->secret('password');

    Livewire::test(Manage::class)
        ->call('edit', $sender->id)
        ->assertSet('password', '') // el secreto nunca se prellena
        ->set('name', 'Academics MCA')
        ->call('save')
        ->assertHasNoErrors();

    $sender->refresh();
    expect($sender->name)->toBe('Academics MCA');
    expect($sender->secret('password'))->toBe($original); // no se borró
});

it('"Probar envio" manda un correo desde la direccion del remitente y sella el resultado', function () {
    Mail::fake();
    mailAdmin();
    $sender = EmailSender::factory()->create(['from_address' => 'finanzas@mcaschool.education', 'name' => 'Finanzas MCA']);

    Livewire::test(Manage::class)
        ->set('testTo', 'destino@example.com')
        ->call('test', $sender->id)
        ->assertHasNoErrors();

    Mail::assertSent(OutboundEmail::class, function (OutboundEmail $mail) {
        return $mail->hasTo('destino@example.com')
            && $mail->fromAddress === 'finanzas@mcaschool.education'
            && $mail->fromName === 'Finanzas MCA';
    });

    $sender->refresh();
    expect($sender->last_test_ok)->toBeTrue();
    expect($sender->last_tested_at)->not->toBeNull();
});

it('la prueba exige un correo de destino valido', function () {
    Mail::fake();
    mailAdmin();
    $sender = EmailSender::factory()->create();

    Livewire::test(Manage::class)
        ->set('testTo', 'no-es-correo')
        ->call('test', $sender->id)
        ->assertHasErrors('testTo');

    Mail::assertNothingSent();
});

it('solo Administrador accede a los remitentes (Marketing NO)', function () {
    mailAdmin('marketing');

    Livewire::test(Manage::class)->assertForbidden();
});
