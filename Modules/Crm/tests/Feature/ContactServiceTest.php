<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
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
