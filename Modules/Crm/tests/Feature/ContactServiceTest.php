<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Exceptions\ContactIdentityConflictException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\ContactService;
use Modules\Institutions\Models\Institution;

function crmContext(): Institution
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return $institution;
}

it('no duplica un contacto con el mismo correo: enriquece el existente', function () {
    crmContext();
    $service = app(ContactService::class);

    $first = $service->createOrUpdate([
        'email' => 'Ana@Example.com', 'first_name' => 'Ana',
    ]);

    $second = $service->createOrUpdate([
        'email' => 'ana@example.com', 'first_name' => 'Ana Maria', 'phone' => '+52 555', 'country' => 'MX',
    ]);

    // Mismo contacto (dedup por institution_id + email, normalizado a minusculas).
    expect(Contact::query()->count())->toBe(1);
    expect($second->id)->toBe($first->id);
    expect($second->first_name)->toBe('Ana Maria');
    expect($second->phone)->toBe('+52 555');
    expect($second->country)->toBe('MX');
});

it('no borra datos existentes con valores vacios', function () {
    crmContext();
    $service = app(ContactService::class);

    $service->createOrUpdate(['email' => 'luis@example.com', 'first_name' => 'Luis', 'phone' => '111']);
    $updated = $service->createOrUpdate(['email' => 'luis@example.com', 'phone' => '']);

    expect($updated->first_name)->toBe('Luis');
    expect($updated->phone)->toBe('111');
});

it('sella el consentimiento una sola vez', function () {
    crmContext();
    $service = app(ContactService::class);

    $contact = $service->createOrUpdate(['email' => 'c@example.com', 'first_name' => 'Cris', 'consent' => true, 'consent_source' => 'widget']);
    expect($contact->consent_at)->not->toBeNull();
    $firstConsent = $contact->consent_at;

    $again = $service->createOrUpdate(['email' => 'c@example.com', 'consent' => true, 'consent_source' => 'otro']);
    // No se re-sella ni se cambia la fuente.
    expect($again->consent_at->equalTo($firstConsent))->toBeTrue();
    expect($again->consent_source)->toBe('widget');
});

// --- Identidad por EMAIL O TELÉFONO --------------------------------------------

it('crea un contacto SOLO con teléfono: email queda NULL (no cadena vacía) y guarda el normalizado', function () {
    crmContext();
    $service = app(ContactService::class);

    $c = $service->createOrUpdate(['first_name' => 'Tel', 'phone' => '+1 809 555 1234']);

    expect($c->email)->toBeNull();               // NULL, nunca ''
    expect($c->phone)->toBe('+1 809 555 1234');  // crudo = presentación
    expect($c->phone_normalized)->toBe('+18095551234');
});

it('deduplica por TELÉFONO NORMALIZADO cuando no llega email (mismo teléfono con distinto formato)', function () {
    crmContext();
    $service = app(ContactService::class);

    $first = $service->createOrUpdate(['first_name' => 'Ana', 'phone' => '+1 809 555 1234']);
    $second = $service->createOrUpdate(['first_name' => 'Ana R', 'phone' => '+18095551234']);

    expect(Contact::query()->count())->toBe(1);
    expect($second->id)->toBe($first->id);
    expect($second->first_name)->toBe('Ana R');
});

it('actualiza por teléfono sin inventar email', function () {
    crmContext();
    $service = app(ContactService::class);

    $first = $service->createOrUpdate(['first_name' => 'Sin', 'phone' => '+34600111222']);
    $second = $service->createOrUpdate(['phone' => '+34 600 111 222', 'country' => 'ES']);

    expect($second->id)->toBe($first->id);
    expect($second->email)->toBeNull();
    expect($second->country)->toBe('ES');
});

it('permite varios contactos con email NULL en la misma institución', function () {
    crmContext();
    $service = app(ContactService::class);

    $service->createOrUpdate(['first_name' => 'Uno', 'phone' => '+34600000001']);
    $service->createOrUpdate(['first_name' => 'Dos', 'phone' => '+34600000002']);

    expect(Contact::query()->whereNull('email')->count())->toBe(2);
});

it('nunca deduplica por el teléfono SIN normalizar (número nacional ambiguo → contactos distintos)', function () {
    crmContext();
    $service = app(ContactService::class);

    // Sin '+' ni país → normalizado null: cada uno es un contacto propio (no se fusionan por crudo).
    $a = $service->createOrUpdate(['first_name' => 'A', 'email' => 'a@x.com', 'phone' => '5551234']);
    $b = $service->createOrUpdate(['first_name' => 'B', 'email' => 'b@x.com', 'phone' => '5551234']);

    expect($a->id)->not->toBe($b->id);
    expect($a->phone_normalized)->toBeNull();
    expect($b->phone_normalized)->toBeNull();
});

it('el mismo teléfono en instituciones DISTINTAS no se cruza (aislamiento)', function () {
    $service = app(ContactService::class);

    $instA = Institution::factory()->create();
    $instB = Institution::factory()->create();

    $a = app(CurrentInstitution::class)->runFor($instA->id, fn () => $service->createOrUpdate(['first_name' => 'A', 'phone' => '+18095559999']));
    $b = app(CurrentInstitution::class)->runFor($instB->id, fn () => $service->createOrUpdate(['first_name' => 'B', 'phone' => '+18095559999']));

    expect($a->id)->not->toBe($b->id);
    expect($a->institution_id)->toBe($instA->id);
    expect($b->institution_id)->toBe($instB->id);
});

it('conflicto de identidad (email→A, teléfono→B): en modo estricto lanza y NO fusiona', function () {
    crmContext();
    $service = app(ContactService::class);

    $service->createOrUpdate(['email' => 'a@x.com', 'first_name' => 'A']);                 // contacto A (email)
    $service->createOrUpdate(['first_name' => 'B', 'phone' => '+18095551234']);            // contacto B (teléfono)

    // email→A, teléfono→B: en estricto, conflicto controlado.
    expect(fn () => $service->createOrUpdate(['email' => 'a@x.com', 'phone' => '+18095551234'], strictIdentityConflict: true))
        ->toThrow(ContactIdentityConflictException::class);

    // No se fusionó ni se creó nada: siguen 2 contactos.
    expect(Contact::query()->count())->toBe(2);
});

it('conflicto en modo NO estricto: el email manda y no reasigna el teléfono del otro contacto', function () {
    crmContext();
    $service = app(ContactService::class);

    $a = $service->createOrUpdate(['email' => 'a@x.com', 'first_name' => 'A']);
    $b = $service->createOrUpdate(['first_name' => 'B', 'phone' => '+18095551234']);

    $r = $service->createOrUpdate(['email' => 'a@x.com', 'phone' => '+18095551234']); // no estricto

    expect($r->id)->toBe($a->id);                 // el email manda
    expect($b->fresh()->phone_normalized)->toBe('+18095551234'); // B conserva su teléfono
    expect(Contact::query()->count())->toBe(2);   // sin fusiones
});
