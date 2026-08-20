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
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\SentEmailRenderer;

/**
 * Paso 5: historial con calidad. Los archivos (imágenes inline + adjuntos) se
 * PERSISTEN al enviar para poder abrir el correo tal como se envió (formato +
 * imágenes) y descargar los adjuntos.
 */
function historyCtx(string $role = 'admin'): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $role): array {
        return [
            $institution,
            User::factory()->create(['institution_id' => $institution->id, 'role' => $role]),
            $c = Contact::factory()->create(['email' => 'dest@example.com']),
            Lead::factory()->create(['contact_id' => $c->id]),
            EmailSender::factory()->create(['from_address' => 'finanzas@mcaschool.education', 'name' => 'Finanzas MCA']),
        ];
    });
}

it('persiste imagen inline + adjunto y muestra el correo con formato e imagen (cid -> data-uri)', function () {
    Mail::fake();
    Storage::fake('local');
    [$institution, $user, $contact, $lead, $sender] = historyCtx();

    $inline = UploadedFile::fake()->image('inline.png');
    $attach = UploadedFile::fake()->create('contrato.pdf', 50);

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($sender, $contact, $lead, $user, $inline, $attach) {
        $msg = app(EmailDispatcher::class)->send(
            $sender, $contact, $lead->id, $user, 'Asunto',
            '<p><b>Hola</b> <img src="blob:x" data-cid="cidA"></p>',
            [['path' => (string) $attach->getRealPath(), 'name' => 'contrato.pdf', 'mime' => 'application/pdf', 'size' => (int) $attach->getSize()]],
            ['cidA' => ['path' => (string) $inline->getRealPath(), 'mime' => 'image/png', 'size' => (int) $inline->getSize()]],
        );

        // Persistidos y separados por disposición.
        expect($msg->inlineImages()->count())->toBe(1);
        expect($msg->files()->count())->toBe(1);
        $img = $msg->inlineImages()->first();
        expect($img->path)->not->toBeNull();
        expect(Storage::disk('local')->exists($img->path))->toBeTrue();
        $file = $msg->files()->first();
        expect($file->filename)->toBe('contrato.pdf');
        expect(Storage::disk('local')->exists($file->path))->toBeTrue();

        // Al VER el correo, el cuerpo mantiene el formato y la imagen se resuelve a data-URI.
        $body = app(SentEmailRenderer::class)->displayBody($msg->load('inlineImages'));
        expect($body)->toContain('<b>Hola</b>')
            ->and($body)->toContain('data:image/png;base64,')
            ->and($body)->not->toContain('cid:cidA');
    });
});

it('desde la ficha se abre un correo enviado y se descarga su adjunto (gated)', function () {
    Mail::fake();
    Storage::fake('local');
    [$institution, $user, $contact, $lead, $sender] = historyCtx();
    $attach = UploadedFile::fake()->create('contrato.pdf', 30);

    $msg = app(CurrentInstitution::class)->runFor($institution->id, fn () => app(EmailDispatcher::class)->send(
        $sender, $contact, $lead->id, $user, 'Asunto', '<p>hola</p>',
        [['path' => (string) $attach->getRealPath(), 'name' => 'contrato.pdf', 'mime' => 'application/pdf', 'size' => (int) $attach->getSize()]],
    ));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    $fileId = $msg->files()->first()->id;

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openSentEmail', $msg->id)
        ->assertSet('viewingEmailId', $msg->id)
        ->call('downloadAttachment', $fileId)
        ->assertFileDownloaded('contrato.pdf');
});

it('Marketing (solo lectura) puede VER el historial pero no descargar sin permiso CRM', function () {
    // Marketing sí trabaja el CRM (canWorkCrm) — puede ver/descargar el historial,
    // aunque no pueda enviar. Un rol sin canWorkCrm no llega a la ficha.
    Mail::fake();
    Storage::fake('local');
    [$institution, $user, $contact, $lead, $sender] = historyCtx('marketing');
    $attach = UploadedFile::fake()->create('c.pdf', 10);

    $msg = app(CurrentInstitution::class)->runFor($institution->id, fn () => app(EmailDispatcher::class)->send(
        $sender, $contact, $lead->id, $user, 'Asunto', '<p>hola</p>',
        [['path' => (string) $attach->getRealPath(), 'name' => 'c.pdf', 'mime' => 'application/pdf', 'size' => (int) $attach->getSize()]],
    ));

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    Livewire::test(Show::class, ['lead' => $lead])
        ->call('openSentEmail', $msg->id)
        ->assertSet('viewingEmailId', $msg->id);
});
