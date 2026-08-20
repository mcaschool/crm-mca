<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\AttachmentValidator;
use Modules\Notifications\Support\EmailHtmlSanitizer;
use Modules\Notifications\Support\InlineImageEmbedder;

/**
 * Paso 4: imágenes inline embebidas (CID). Deben viajar DENTRO del mensaje para
 * verse en Gmail/Outlook (no enlace externo, no base64 en el HTML), con validación
 * de tamaño/tipo en el servidor.
 */
it('reescribe <img data-cid> a src="cid:…" solo para las imágenes subidas; descarta las demás', function () {
    $embedder = new InlineImageEmbedder;

    [$html, $used] = $embedder->embed(
        '<p>Uno <img src="blob:x" data-cid="abc"> Dos <img src="http://evil/track.png"></p>',
        ['abc' => ['path' => '/tmp/x.png', 'mime' => 'image/png', 'size' => 100]],
    );

    expect($html)->toContain('src="cid:abc"')      // la nuestra: embebida por CID
        ->and($html)->not->toContain('data-cid')    // se limpia la marca
        ->and($html)->not->toContain('http://evil'); // la externa: descartada
    expect($used)->toHaveCount(1);
    expect($used[0]['cid'])->toBe('abc');
});

it('el sanitizador conserva <img data-cid> pero elimina src y on*', function () {
    $out = (new EmailHtmlSanitizer)->sanitize('<img src="http://evil/x.png" onerror="alert(1)" data-cid="abc" alt="foto">');

    expect($out)->toContain('data-cid="abc"')
        ->and($out)->not->toContain('onerror')
        ->and($out)->not->toContain('http://evil')
        ->and($out)->not->toContain('src=');
});

it('valida en el SERVIDOR las imágenes inline (solo imágenes, tamaño máximo)', function () {
    $v = new AttachmentValidator;

    expect($v->imageError(UploadedFile::fake()->create('doc.pdf', 100)))->not->toBeNull();     // no es imagen
    expect($v->imageError(UploadedFile::fake()->create('malware.exe', 10)))->not->toBeNull();   // no es imagen
    expect($v->imageError(UploadedFile::fake()->image('grande.png')->size(6000)))->not->toBeNull(); // > 5 MB
    expect($v->imageError(UploadedFile::fake()->image('ok.png')))->toBeNull();                  // válida
});

it('la imagen inline viaja EMBEBIDA por CID (multipart/related, inline), no externa ni base64 en el HTML', function () {
    $img = UploadedFile::fake()->image('foto.png', 30, 30);

    Mail::mailer('array')->to('dest@example.com')->send(new OutboundEmail(
        'finanzas@mcaschool.education', 'Finanzas', 'Asunto',
        '<p>Mira: <img src="cid:abc123"></p>', [],
        [['path' => (string) $img->getRealPath(), 'cid' => 'abc123', 'mime' => 'image/png']],
    ));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $mime = $email->toString();

    // El cuerpo referencia la imagen por CID (no un http externo ni un data: base64).
    expect((string) $email->getHtmlBody())->toContain('cid:abc123');
    // Estructura de imagen embebida inline.
    expect($mime)->toContain('multipart/related')
        ->and($mime)->toContain('Content-Disposition: inline')
        ->and($mime)->toContain('image/png');
});

it('al enviar, el cuerpo guardado referencia la imagen por cid:', function () {
    Mail::fake();
    $institution = Institution::factory()->create();

    [$user, $contact, $lead, $sender] = app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution) {
        return [
            User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']),
            $c = Contact::factory()->create(['email' => 'x@example.com']),
            Lead::factory()->create(['contact_id' => $c->id]),
            EmailSender::factory()->create(),
        ];
    });

    $img = UploadedFile::fake()->image('foto.png');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($sender, $contact, $lead, $user, $img) {
        $msg = app(EmailDispatcher::class)->send(
            $sender, $contact, $lead->id, $user, 'Asunto',
            '<p>Foto: <img src="blob:preview" data-cid="cidUno"></p>',
            [],
            ['cidUno' => ['path' => (string) $img->getRealPath(), 'mime' => 'image/png', 'size' => 100]],
        );

        expect($msg->body)->toContain('src="cid:cidUno"')
            ->and($msg->body)->not->toContain('blob:')
            ->and($msg->body)->not->toContain('data-cid');
    });
});
