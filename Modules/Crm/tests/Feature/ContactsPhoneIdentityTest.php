<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

/**
 * Esquema resultante de la migración de identidad email-O-teléfono (aplicada por
 * RefreshDatabase): columna phone_normalized, email nullable, unicidad institucional del
 * teléfono normalizado con múltiples NULL permitidos. El up/down completo y el backfill se
 * validan además ejecutando migrate:fresh y migrate:rollback en base limpia (validación final).
 */
it('migración up: existe phone_normalized y email es nullable', function () {
    expect(Schema::hasColumn('contacts', 'phone_normalized'))->toBeTrue();

    // email nullable: se puede crear un contacto sin email (solo teléfono).
    $inst = Institution::factory()->create();
    $contact = app(CurrentInstitution::class)->runFor($inst->id, fn () => Contact::query()->create([
        'first_name' => 'Tel', 'email' => null, 'phone' => '+18095551234', 'phone_normalized' => '+18095551234',
    ]));
    expect($contact->email)->toBeNull();
});

it('unicidad institucional (institution_id, phone_normalized): rechaza duplicados', function () {
    $inst = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        Contact::query()->create(['first_name' => 'A', 'email' => null, 'phone_normalized' => '+18095550000']);

        expect(fn () => Contact::query()->create(['first_name' => 'B', 'email' => null, 'phone_normalized' => '+18095550000']))
            ->toThrow(QueryException::class);
    });
});

it('permite múltiples contactos con phone_normalized NULL (y email NULL)', function () {
    $inst = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        Contact::query()->create(['first_name' => 'A', 'email' => null, 'phone' => '5551', 'phone_normalized' => null]);
        Contact::query()->create(['first_name' => 'B', 'email' => null, 'phone' => '5552', 'phone_normalized' => null]);

        expect(Contact::query()->whereNull('phone_normalized')->count())->toBe(2);
    });
});
