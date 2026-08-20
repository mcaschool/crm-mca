<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Livewire\EmailTemplates\Manage;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Models\EmailTemplate;
use Modules\Notifications\Policies\EmailTemplatePolicy;
use Modules\Notifications\Support\TemplateBodyImages;

/**
 * Repositorio de plantillas de correo. Demuestra los PERMISOS ESTRICTOS con evidencia:
 *  - Compartidas: solo Admin crea/edita/borra; cualquiera que envíe las usa.
 *  - Propias: cada usuario gestiona LAS SUYAS; nadie ve las de otro.
 *  - Acotadas por institución. Con etiquetas dinámicas e imágenes que viajan por CID.
 */
function tplInstitution(): Institution
{
    return Institution::factory()->create();
}

/** Usuario del panel con rol/departamento (User no usa scope de institución). */
function tplUser(Institution $institution, string $role = 'admissions', ?string $department = null): User
{
    return User::factory()->create([
        'institution_id' => $institution->id,
        'role' => $role,
        'department' => $department,
    ]);
}

// ---------------------------------------------------------------------------
// PERMISOS (evidencia directa sobre la Policy)
// ---------------------------------------------------------------------------

it('COMPARTIDAS: solo el Administrador las crea/edita/borra; un no-admin que envía NO puede', function () {
    $institution = tplInstitution();
    $admin = tplUser($institution, 'admin');
    $sender = tplUser($institution, 'admissions'); // envía correo, pero NO es admin

    $shared = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->create(['user_id' => null]));

    $policy = new EmailTemplatePolicy;

    // Admin: CRUD completo sobre la compartida.
    expect($policy->createShared($admin))->toBeTrue();
    expect($policy->update($admin, $shared))->toBeTrue();
    expect($policy->delete($admin, $shared))->toBeTrue();

    // No-admin (asesor que envía): NO crea, NO edita, NO borra una compartida…
    expect($policy->createShared($sender))->toBeFalse();
    expect($policy->update($sender, $shared))->toBeFalse();
    expect($policy->delete($sender, $shared))->toBeFalse();

    // …pero SÍ puede verla/usarla (y crear las suyas propias).
    expect($policy->view($sender, $shared))->toBeTrue();
    expect($policy->createOwn($sender))->toBeTrue();
});

it('PROPIAS: cada usuario gestiona las suyas; otro usuario NO la ve ni la toca', function () {
    $institution = tplInstitution();
    $ana = tplUser($institution, 'admissions');
    $beto = tplUser($institution, 'admissions');

    $deAna = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->propia($ana->id)->create());

    $policy = new EmailTemplatePolicy;

    // Ana: ve, edita y borra LA SUYA.
    expect($policy->view($ana, $deAna))->toBeTrue();
    expect($policy->update($ana, $deAna))->toBeTrue();
    expect($policy->delete($ana, $deAna))->toBeTrue();

    // Beto: NO la ve, NO la edita, NO la borra (privada de Ana).
    expect($policy->view($beto, $deAna))->toBeFalse();
    expect($policy->update($beto, $deAna))->toBeFalse();
    expect($policy->delete($beto, $deAna))->toBeFalse();

    // Incluso un Admin no "gestiona" la privada de otro (no es compartida ni suya).
    $admin = tplUser($institution, 'admin');
    expect($policy->update($admin, $deAna))->toBeFalse();
    expect($policy->view($admin, $deAna))->toBeFalse();
});

it('AISLAMIENTO por institución: una plantilla de otra institución no se ve ni se gestiona', function () {
    $instA = tplInstitution();
    $instB = tplInstitution();
    $userA = tplUser($instA, 'admin');

    $sharedB = app(CurrentInstitution::class)->runFor($instB->id, fn () => EmailTemplate::factory()->create(['user_id' => null]));

    $policy = new EmailTemplatePolicy;
    expect($policy->view($userA, $sharedB))->toBeFalse();
    expect($policy->update($userA, $sharedB))->toBeFalse();
    expect($policy->delete($userA, $sharedB))->toBeFalse();
});

it('Marketing (no envía) no entra al repositorio de plantillas', function () {
    $institution = tplInstitution();
    $marketing = tplUser($institution, 'marketing');

    expect((new EmailTemplatePolicy)->viewAny($marketing))->toBeFalse();
});

// ---------------------------------------------------------------------------
// PANTALLA DE GESTIÓN (Livewire) — el gating también se aplica en las acciones
// ---------------------------------------------------------------------------

it('la pantalla de COMPARTIDAS (Ajustes) está vedada a un no-admin', function () {
    $institution = tplInstitution();
    $sender = tplUser($institution, 'admissions');

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($sender);

    Livewire::test(Manage::class, ['scope' => 'shared'])->assertForbidden();
});

it('el Admin crea una plantilla COMPARTIDA (sin dueño) desde Ajustes', function () {
    $institution = tplInstitution();
    $admin = tplUser($institution, 'admin');

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    Livewire::test(Manage::class, ['scope' => 'shared'])
        ->call('newTemplate')
        ->set('name', 'Bienvenida')
        ->set('subject', 'Hola [Nombre]')
        ->set('body', '<p>Hola <strong>[Nombre]</strong>, bienvenido.</p>')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $tpl = EmailTemplate::query()->first();
        expect($tpl)->not->toBeNull();
        expect($tpl->user_id)->toBeNull();          // compartida
        expect($tpl->name)->toBe('Bienvenida');
        expect($tpl->body)->toContain('<strong>[Nombre]</strong>'); // etiqueta cruda hasta el envío
    });
});

it('un no-admin NO puede borrar una plantilla COMPARTIDA (acción vedada)', function () {
    $institution = tplInstitution();
    $sender = tplUser($institution, 'admissions');
    $shared = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->create(['user_id' => null]));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($sender);

    // Aunque monte su pantalla de PROPIAS, no puede borrar una compartida por id.
    Livewire::test(Manage::class, ['scope' => 'mine'])
        ->call('delete', $shared->id)
        ->assertForbidden();

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(EmailTemplate::query()->whereKey($shared->id)->exists())->toBeTrue());
});

it('cada usuario ve SOLO sus propias en "Mis plantillas"; no las de otro', function () {
    $institution = tplInstitution();
    $ana = tplUser($institution, 'admissions');
    $beto = tplUser($institution, 'admissions');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($ana, $beto) {
        EmailTemplate::factory()->propia($ana->id)->create(['name' => 'Plantilla de Ana']);
        EmailTemplate::factory()->propia($beto->id)->create(['name' => 'Plantilla de Beto']);
    });

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($ana);

    Livewire::test(Manage::class, ['scope' => 'mine'])
        ->assertSee('Plantilla de Ana')
        ->assertDontSee('Plantilla de Beto');
});

it('un usuario NO puede editar la plantilla PROPIA de otro (acción vedada)', function () {
    $institution = tplInstitution();
    $ana = tplUser($institution, 'admissions');
    $beto = tplUser($institution, 'admissions');
    $deAna = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->propia($ana->id)->create());

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($beto);

    Livewire::test(Manage::class, ['scope' => 'mine'])
        ->call('edit', $deAna->id)
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// USO EN EL COMPOSITOR
// ---------------------------------------------------------------------------

function tplComposeCtx(string $role = 'admissions'): array
{
    $institution = tplInstitution();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $role): array {
        $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
        $contact = Contact::factory()->create(['first_name' => 'Alverto', 'email' => 'alverto@example.com']);
        $lead = Lead::factory()->create(['contact_id' => $contact->id, 'area' => 'Corporativo']);
        $sender = EmailSender::factory()->create(['name' => 'Finanzas MCA', 'from_address' => 'finanzas@mcaschool.education']);

        return [$institution, $user, $contact, $lead, $sender];
    });
}

it('al redactar, elegir una plantilla COMPARTIDA carga asunto y cuerpo', function () {
    [$institution, $user, $contact, $lead] = tplComposeCtx('admissions');
    $tpl = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->create([
        'user_id' => null,
        'subject' => 'Hola [Nombre]',
        'body' => '<p>Gracias por tu interés en [Área].</p>',
    ]));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->call('loadTemplate', (string) $tpl->id)
        ->assertSet('emailSubject', 'Hola [Nombre]')
        ->assertSet('emailBody', '<p>Gracias por tu interés en [Área].</p>');
});

it('al redactar, NO se puede cargar la plantilla PROPIA de otro usuario', function () {
    [$institution, $user, $contact, $lead] = tplComposeCtx('admissions');
    $otro = app(CurrentInstitution::class)->runFor($institution->id, fn () => User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions']));
    $ajena = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->propia($otro->id)->create(['subject' => 'PRIVADA AJENA']));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    // No se carga (queda vacío); no revela la plantilla ajena.
    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->call('loadTemplate', (string) $ajena->id)
        ->assertSet('emailSubject', '')
        ->assertSet('emailBody', '');
});

it('enviar con una plantilla resuelve sus etiquetas dinámicas con el dato real', function () {
    Mail::fake();
    [$institution, $user, $contact, $lead, $sender] = tplComposeCtx('admissions');
    $tpl = app(CurrentInstitution::class)->runFor($institution->id, fn () => EmailTemplate::factory()->create([
        'user_id' => null,
        'subject' => 'Hola [Nombre]',
        'body' => '<p>Vimos tu interés en [Área].</p>',
    ]));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->call('loadTemplate', (string) $tpl->id)
        ->set('emailSenderId', (string) $sender->id)
        ->call('sendEmail')
        ->assertHasNoErrors()
        ->assertSet('composeOpen', false);

    Mail::assertSent(OutboundEmail::class);
    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $msg = EmailMessage::query()->where('status', 'sent')->first();
        expect($msg->subject)->toBe('Hola Alverto');
        expect($msg->body)->toContain('Vimos tu interés en Corporativo')
            ->and($msg->body)->not->toContain('[Nombre]')
            ->and($msg->body)->not->toContain('[Área]');
    });
});

it('una plantilla con IMAGEN inline: al usarla, la imagen viaja embebida por CID', function () {
    Mail::fake();
    Storage::fake('local');
    [$institution, $user, $contact, $lead, $sender] = tplComposeCtx('admissions');

    $img = UploadedFile::fake()->image('logo.png');

    // Plantilla compartida con una imagen inline persistida (como la deja el editor).
    $tpl = app(CurrentInstitution::class)->runFor($institution->id, function () use ($img) {
        $tpl = EmailTemplate::factory()->create([
            'user_id' => null,
            'subject' => 'Con imagen',
            'body' => '<p>Mira: <img data-cid="cidLogo"></p>',
        ]);
        app(TemplateBodyImages::class)->persist($tpl, (string) $tpl->body, [
            'cidLogo' => ['path' => (string) $img->getRealPath(), 'mime' => 'image/png', 'size' => (int) $img->getSize()],
        ]);

        return $tpl;
    });

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect($tpl->images()->count())->toBe(1));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openCompose')
        ->call('loadTemplate', (string) $tpl->id)
        ->set('emailSenderId', (string) $sender->id)
        ->call('sendEmail')
        ->assertHasNoErrors();

    Mail::assertSent(OutboundEmail::class);
    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $msg = EmailMessage::query()->where('status', 'sent')->first();
        expect($msg->body)->toContain('cid:cidLogo');          // referenciada por CID
        expect($msg->inlineImages()->count())->toBe(1);        // viaja embebida (no externa)
    });
});
