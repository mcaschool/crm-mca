<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\TagResolver;

/**
 * Paso 3: etiquetas dinámicas. Se rellenan con datos reales del destinatario al
 * enviar; fallback claro cuando falta el dato; nunca se deja la etiqueta cruda.
 */
it('resuelve [Nombre]/[Área] con datos reales y aplica fallback cuando faltan', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $resolver = new TagResolver;

        $contact = Contact::factory()->create(['first_name' => 'Alverto', 'last_name' => 'Empresa', 'email' => 'alverto@example.com']);
        $lead = Lead::factory()->create(['contact_id' => $contact->id, 'area' => 'Corporativo']);

        $map = $resolver->map($contact, $lead);
        expect($map['Nombre'])->toBe('Alverto');
        expect($map['Área'])->toBe('Corporativo');
        expect($map['Correo'])->toBe('alverto@example.com');
        expect($map['Programa'])->toBe('nuestros programas'); // sin programa -> fallback

        expect($resolver->resolveText('Hola [Nombre]', $map))->toBe('Hola Alverto');
        expect($resolver->resolveHtml('<p>Hola [Nombre], te interesó [Área].</p>', $map))
            ->toBe('<p>Hola Alverto, te interesó Corporativo.</p>');

        // Solo se ofrecen en el menú las etiquetas CON dato (Programa no está).
        $tags = collect($resolver->available($contact, $lead))->pluck('tag');
        expect($tags)->toContain('Nombre', 'Correo', 'Área');
        expect($tags)->not->toContain('Programa');
    });
});

it('con nombre faltante usa el fallback, nunca deja "[Nombre]" crudo', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $resolver = new TagResolver;
        $contact = Contact::factory()->create(['first_name' => '', 'email' => 'x@example.com']);

        $out = $resolver->resolveText('Hola [Nombre], ¿cómo estás?', $resolver->map($contact, null));
        expect($out)->toBe('Hola estimado/a, ¿cómo estás?')->and($out)->not->toContain('[Nombre]');
    });
});

it('escapa el valor de la etiqueta en el cuerpo HTML (un nombre con HTML no inyecta)', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $resolver = new TagResolver;
        $contact = Contact::factory()->create(['first_name' => '<b>evil</b>', 'email' => 'x@example.com']);

        $html = $resolver->resolveHtml('<p>Hola [Nombre]</p>', $resolver->map($contact, null));
        expect($html)->toContain('&lt;b&gt;evil&lt;/b&gt;')->and($html)->not->toContain('<b>evil</b>');
    });
});

it('al enviar, el correo guardado y mandado tiene las etiquetas RESUELTAS', function () {
    Mail::fake();
    $institution = Institution::factory()->create();

    [$user, $contact, $lead, $sender] = app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution) {
        return [
            User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']),
            $c = Contact::factory()->create(['first_name' => 'Alverto', 'email' => 'alverto@example.com']),
            Lead::factory()->create(['contact_id' => $c->id, 'area' => 'Corporativo']),
            EmailSender::factory()->create(['from_address' => 'finanzas@mcaschool.education', 'name' => 'Finanzas MCA']),
        ];
    });

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($sender, $contact, $lead, $user) {
        $msg = app(EmailDispatcher::class)->send(
            $sender, $contact, $lead->id, $user,
            'Hola [Nombre]', '<p>Hola [Nombre], vimos tu interés en [Área].</p>',
        );

        expect($msg->subject)->toBe('Hola Alverto');
        expect($msg->body)->toContain('Hola Alverto, vimos tu interés en Corporativo');
        expect($msg->body)->not->toContain('[Nombre]')->and($msg->body)->not->toContain('[Área]');
    });
});
