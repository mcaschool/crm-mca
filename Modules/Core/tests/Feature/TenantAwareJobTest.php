<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Core\Tests\Support\CountContactsJob;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

/**
 * Tapon de la fuga #1 (colas): un job restablece el contexto de SU institucion
 * al ejecutarse, aunque el worker no tenga contexto ambiente.
 */
it('un job aislado por institucion solo ve los datos de la suya', function () {
    $context = app(CurrentInstitution::class);

    $a = Institution::factory()->create();
    $b = Institution::factory()->create();

    // 2 contactos en A, 3 en B.
    $context->runFor($a->id, fn () => Contact::factory()->count(2)->create());
    $context->runFor($b->id, fn () => Contact::factory()->count(3)->create());

    // Se construye el job para A y se BORRA el contexto ambiente (simula el worker).
    CountContactsJob::$lastSeenCount = null;
    $job = new CountContactsJob($a->id);
    $context->forget();

    // El sync ejecuta handle() de inmediato; el job restablece el contexto de A.
    dispatch_sync($job);

    expect(CountContactsJob::$lastSeenCount)->toBe(2);
});

it('el contexto ambiente queda intacto tras ejecutar el job', function () {
    $context = app(CurrentInstitution::class);

    $a = Institution::factory()->create();
    $b = Institution::factory()->create();

    $context->set($b->id);
    $job = new CountContactsJob($a->id);
    dispatch_sync($job);

    // Tras el job, el contexto vuelve a B (no se queda pegado en A).
    expect($context->id())->toBe($b->id);
});
