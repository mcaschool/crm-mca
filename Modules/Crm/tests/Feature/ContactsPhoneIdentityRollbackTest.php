<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

/**
 * Rollback DEFENSIVO de la migración de identidad email-O-teléfono.
 *
 * Escenario crítico: tras crear un contacto phone-only (email NULL), revertir a email NOT
 * NULL borraría/inventaría datos. down() debe DETENERSE antes de tocar el esquema (sin
 * rollback parcial). Los escenarios "bloqueado" no ejecutan DDL (la guarda lanza primero),
 * por lo que son seguros bajo RefreshDatabase transaccional. El down() exitoso (sin emails
 * nulos) usa try/finally para restaurar el esquema y no contaminar el resto de la suite.
 */
function phoneIdentityMigration(): object
{
    return require base_path('Modules/Crm/database/migrations/2026_09_26_120000_add_phone_identity_to_contacts.php');
}

it('1) down() exitoso cuando NO existen emails nulos (revierte y se restaura)', function () {
    // Estado limpio: RefreshDatabase migró y no hay contactos → no hay emails nulos.
    expect(DB::table('contacts')->whereNull('email')->count())->toBe(0);
    $migration = phoneIdentityMigration();

    try {
        $migration->down();
        expect(Schema::hasColumn('contacts', 'phone_normalized'))->toBeFalse(); // columna revertida
        $email = collect(DB::select('SHOW COLUMNS FROM contacts'))->firstWhere('Field', 'email');
        expect($email->Null)->toBe('NO'); // email NOT NULL restaurado
    } finally {
        $migration->up(); // restaura el esquema para el resto de la suite
    }

    expect(Schema::hasColumn('contacts', 'phone_normalized'))->toBeTrue();
});

it('2) down() BLOQUEADO de forma controlada cuando existe un contacto sin email', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => Contact::query()->create([
        'first_name' => 'Tel', 'last_name' => 'Only', 'email' => null,
        'phone' => '+18095551234', 'phone_normalized' => '+18095551234',
    ]));

    expect(fn () => phoneIdentityMigration()->down())->toThrow(RuntimeException::class);
});

it('3) el contacto phone-only permanece INTACTO tras el rollback bloqueado', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => Contact::query()->create([
        'first_name' => 'Tel', 'last_name' => 'Only', 'email' => null,
        'phone' => '+18095551234', 'phone_normalized' => '+18095551234',
    ]));

    try {
        phoneIdentityMigration()->down();
    } catch (\RuntimeException) {
        // esperado
    }

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        $c = Contact::query()->whereNull('email')->first();
        expect($c)->not->toBeNull();
        expect($c->email)->toBeNull();          // sigue NULL (no se convirtió en '')
        expect($c->phone_normalized)->toBe('+18095551234');
    });
});

it('4) phone_normalized y su índice único permanecen intactos cuando el rollback se bloquea', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => Contact::query()->create([
        'first_name' => 'Tel', 'last_name' => 'Only', 'email' => null,
        'phone' => '+18095551234', 'phone_normalized' => '+18095551234',
    ]));

    try {
        phoneIdentityMigration()->down();
    } catch (\RuntimeException) {
        // esperado
    }

    // La columna sigue existiendo (no hubo rollback parcial).
    expect(Schema::hasColumn('contacts', 'phone_normalized'))->toBeTrue();

    // El índice único sigue vigente: un duplicado del mismo teléfono en la institución falla.
    app(CurrentInstitution::class)->runFor($inst->id, function () {
        expect(fn () => Contact::query()->create([
            'first_name' => 'Dup', 'email' => null, 'phone_normalized' => '+18095551234',
        ]))->toThrow(QueryException::class);
    });
});

it('5 y 6) rollback bloqueado: no inventa emails y no deja el esquema a medias', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => Contact::query()->create([
        'first_name' => 'Tel', 'last_name' => 'Only', 'email' => null,
        'phone' => '+18095551234', 'phone_normalized' => '+18095551234',
    ]));

    try {
        phoneIdentityMigration()->down();
    } catch (\RuntimeException) {
        // esperado
    }

    // No se inventó ningún email (sigue habiendo exactamente 1 contacto y con email null).
    expect(DB::table('contacts')->count())->toBe(1);
    expect(DB::table('contacts')->whereNull('email')->count())->toBe(1);

    // Esquema NO revertido a medias: la columna y el email nullable siguen como en 'up'.
    expect(Schema::hasColumn('contacts', 'phone_normalized'))->toBeTrue();
    $email = collect(DB::select('SHOW COLUMNS FROM contacts'))->firstWhere('Field', 'email');
    expect($email->Null)->toBe('YES'); // sigue nullable (no se tocó el esquema)
});
