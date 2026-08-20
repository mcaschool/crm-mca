<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\AttachmentValidator;
use Modules\Notifications\Support\EmailHtmlSanitizer;

/**
 * Paso 2: editor a pantalla completa. Demuestra las DOS condiciones:
 *  1) el HTML del editor se SANITIZA (scripts/código malicioso neutralizados),
 *  2) los adjuntos se validan en el SERVIDOR (tamaño/total/tipo).
 */
function editorCtx(string $role = 'admin'): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $role): array {
        $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
        $contact = Contact::factory()->create(['email' => 'prospecto@example.com']);
        $lead = Lead::factory()->create(['contact_id' => $contact->id]);
        $sender = EmailSender::factory()->create(['from_address' => 'finanzas@mcaschool.education', 'name' => 'Finanzas MCA']);

        return [$institution, $user, $contact, $lead, $sender];
    });
}

// ---------- CONDICIÓN 1: SANITIZACIÓN ----------

it('sanitiza el HTML del editor: neutraliza scripts, on* y javascript:', function () {
    $s = new EmailHtmlSanitizer;

    $out = $s->sanitize('<p>Hola <b>mundo</b></p><script>alert("xss")</script>');
    expect($out)->not->toContain('<script')
        ->and($out)->not->toContain('alert(')
        ->and($out)->toContain('<b>mundo</b>'); // el formato legítimo se conserva

    $link = $s->sanitize('<a href="javascript:robar()" onclick="robar()">clic</a>');
    expect($link)->not->toContain('javascript:')->and($link)->not->toContain('onclick');

    // <img> se permite (imágenes inline) pero queda INERTE: sin src ni on*. El
    // embebedor descarta luego cualquier <img> que no sea una imagen subida.
    $img = $s->sanitize('<img src=x onerror="alert(1)">texto');
    expect($img)->not->toContain('onerror')->and($img)->not->toContain('alert')->and($img)->not->toContain('src=')->and($img)->toContain('texto');

    expect($s->sanitize('<iframe src="http://evil"></iframe>ok'))->not->toContain('<iframe');
    // Un enlace legítimo sobrevive y se endurece (target/rel).
    $ok = $s->sanitize('<a href="https://mcaschool.education/">web</a>');
    expect($ok)->toContain('href="https://mcaschool.education/"')->and($ok)->toContain('rel="noopener noreferrer"');
});

it('el envío GUARDA y MANDA el cuerpo sanitizado (no el crudo)', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = editorCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($sender, $contact, $lead, $user) {
        $msg = app(EmailDispatcher::class)->send(
            $sender, $contact, $lead->id, $user,
            'Asunto', '<p>Hola</p><script>alert(1)</script>',
        );
        expect($msg->body)->not->toContain('<script')->and($msg->body)->toContain('<p>Hola</p>');
    });

    Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $m) => ! str_contains($m->bodyHtml, '<script'));
});

// ---------- CONDICIÓN 2: VALIDACIÓN DE ADJUNTOS EN EL SERVIDOR ----------

it('valida adjuntos en el SERVIDOR: rechaza por tamaño, por total y por tipo; acepta válidos', function () {
    $v = new AttachmentValidator;

    expect($v->validate([UploadedFile::fake()->create('grande.pdf', 6000)]))->not->toBeEmpty();  // > 5 MB
    expect($v->validate([UploadedFile::fake()->create('malware.exe', 10)]))->not->toBeEmpty();    // tipo no permitido
    expect($v->validate([
        UploadedFile::fake()->create('a.pdf', 8000),
        UploadedFile::fake()->create('b.pdf', 8000),
    ]))->not->toBeEmpty();                                                                          // total > 15 MB
    expect($v->validate([UploadedFile::fake()->image('foto.png')]))->toBeEmpty();                  // válido
});

it('el editor NO envía con un adjunto inválido (validación en el servidor)', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = editorCtx();
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->set('emailSenderId', (string) $sender->id)
        ->set('emailSubject', 'Con adjunto')
        ->set('emailBody', '<p>hola</p>')
        ->set('emailAttachments', [UploadedFile::fake()->create('virus.exe', 10)])
        ->call('sendEmail')
        ->assertHasErrors('emailAttachments');

    Mail::assertNothingSent();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(EmailMessage::query()->count())->toBe(0));
});

it('envía con un adjunto válido y lo registra en el historial (nombre/tipo/tamaño)', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = editorCtx();
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->set('emailSenderId', (string) $sender->id)
        ->set('emailSubject', 'Con adjunto')
        ->set('emailBody', '<p>documento adjunto</p>')
        ->set('emailAttachments', [UploadedFile::fake()->image('foto.png')])
        ->call('sendEmail')
        ->assertHasNoErrors()
        ->assertSet('composeOpen', false);

    Mail::assertSent(OutboundEmail::class);
    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $msg = EmailMessage::query()->where('status', 'sent')->first();
        expect($msg)->not->toBeNull();
        expect($msg->attachments()->count())->toBe(1);
        expect($msg->attachments()->first()->filename)->toBe('foto.png');
    });
});

it('conserva la tipografía elegida (font face de la lista) y descarta las no permitidas', function () {
    $s = new EmailHtmlSanitizer;

    // Fuente permitida: sobrevive para que viaje en el correo.
    expect($s->sanitize('<font face="Arial">Hola</font>'))->toContain('face="Arial"');
    expect($s->sanitize('<font face="Times New Roman">Hola</font>'))->toContain('Times New Roman');

    // Fuente no permitida / intento de inyección: se quita el face (queda inerte).
    $evil = $s->sanitize('<font face="x; background:expression(alert(1))">Hola</font>');
    expect($evil)->not->toContain('expression')->and($evil)->not->toContain('face=');
    expect($s->sanitize('<font face="ComicSansMalicioso">Hola</font>'))->not->toContain('ComicSansMalicioso');
});

it('conserva el tamaño elegido (font size de la escala 1-7) y descarta valores fuera de lista o inyecciones', function () {
    $s = new EmailHtmlSanitizer;

    // Tamaño válido (escala HTML 1–7): sobrevive para que viaje en el correo.
    expect($s->sanitize('<font size="2">Pequeño</font>'))->toContain('size="2"');
    expect($s->sanitize('<font size="7">Título grande</font>'))->toContain('size="7"');

    // Fuera de la escala: se quita el size (queda inerte).
    expect($s->sanitize('<font size="8">X</font>'))->not->toContain('size=');
    expect($s->sanitize('<font size="0">X</font>'))->not->toContain('size=');

    // Intento de inyección en el valor: se quita el size, no queda rastro.
    $evil = $s->sanitize('<font size="3px;background:expression(alert(1))">X</font>');
    expect($evil)->not->toContain('expression')->and($evil)->not->toContain('size=');
});
