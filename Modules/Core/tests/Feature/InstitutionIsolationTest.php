<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;

/**
 * Suite de aislamiento multi-institucion (OBLIGATORIA).
 *
 * Siembra DOS instituciones con datos solapados (mismo correo de contacto, mismo
 * codigo de programa) y verifica que, autenticado el contexto de la institucion A,
 * ninguna consulta cruza a la B.
 */
function tenancyCtx(): CurrentInstitution
{
    return app(CurrentInstitution::class);
}

/**
 * Crea el escenario solapado y devuelve [A, B] con ids.
 *
 * @return array{0: Institution, 1: Institution}
 */
function seedTwoInstitutions(): array
{
    // Institution NO usa el scope: se crea sin contexto.
    $a = Institution::factory()->create(['name' => 'MCA A', 'slug' => 'mca-a']);
    $b = Institution::factory()->create(['name' => 'MCA B', 'slug' => 'mca-b']);

    // Datos SOLAPADOS: mismo email de contacto y mismo codigo de programa en ambas.
    tenancyCtx()->runFor($a->id, function () {
        Contact::factory()->create(['email' => 'ana@example.com', 'first_name' => 'Ana-A']);
        Program::factory()->create(['code' => 'MC-001', 'name_es' => 'Programa A']);
    });

    tenancyCtx()->runFor($b->id, function () {
        Contact::factory()->create(['email' => 'ana@example.com', 'first_name' => 'Ana-B']);
        Program::factory()->create(['code' => 'MC-001', 'name_es' => 'Programa B']);
    });

    return [$a, $b];
}

it('mismo correo y mismo codigo pueden coexistir en dos instituciones', function () {
    [$a, $b] = seedTwoInstitutions();

    // La unicidad es POR institucion: dos contactos con el mismo email en A y B.
    expect(Contact::query()->withoutGlobalScopes()->where('email', 'ana@example.com')->count())->toBe(2);
    expect(Program::query()->withoutGlobalScopes()->where('code', 'MC-001')->count())->toBe(2);
});

it('listar en el contexto A solo devuelve filas de A', function () {
    [$a, $b] = seedTwoInstitutions();

    tenancyCtx()->runFor($a->id, function () use ($a) {
        expect(Contact::query()->count())->toBe(1);
        expect(Contact::query()->first()->first_name)->toBe('Ana-A');
        expect(Program::query()->first()->institution_id)->toBe($a->id);
    });
});

it('acceder por id a una fila de B desde A no la encuentra', function () {
    [$a, $b] = seedTwoInstitutions();

    $bContactId = tenancyCtx()->runFor($b->id, fn () => Contact::query()->first()->id);

    tenancyCtx()->runFor($a->id, function () use ($bContactId) {
        // find() devuelve null (no confirma existencia -> en HTTP seria 404, no 403).
        expect(Contact::query()->find($bContactId))->toBeNull();

        // findOrFail lanza ModelNotFound (traducido a 404 por el handler HTTP).
        expect(fn () => Contact::query()->findOrFail($bContactId))
            ->toThrow(ModelNotFoundException::class);
    });
});

it('crear sin institution_id explicito lo sella con la institucion activa (A)', function () {
    [$a, $b] = seedTwoInstitutions();

    $contact = tenancyCtx()->runFor($a->id, function () {
        return Contact::query()->create([
            'first_name' => 'Nuevo',
            'email' => 'nuevo@example.com',
            'preferred_language' => 'es',
        ]);
    });

    expect($contact->institution_id)->toBe($a->id);
});

it('las escrituras no pueden filtrarse a otra institucion aunque cuelguen de un padre', function () {
    [$a, $b] = seedTwoInstitutions();

    // Un lead creado en A referencia un contacto de A; su institution_id es A.
    $lead = tenancyCtx()->runFor($a->id, function () {
        $contact = Contact::query()->first();

        return Lead::factory()->create(['contact_id' => $contact->id]);
    });

    expect($lead->institution_id)->toBe($a->id);

    // Desde B ese lead no existe.
    tenancyCtx()->runFor($b->id, function () use ($lead) {
        expect(Lead::query()->find($lead->id))->toBeNull();
    });
});

it('el conteo total via modo global ve ambas, pero el filtrado por contexto no', function () {
    [$a, $b] = seedTwoInstitutions();

    $globalTotal = tenancyCtx()->runGlobally(fn () => Contact::query()->count());
    expect($globalTotal)->toBe(2);

    $aTotal = tenancyCtx()->runFor($a->id, fn () => Contact::query()->count());
    expect($aTotal)->toBe(1);
});
