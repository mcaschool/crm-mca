<?php

declare(strict_types=1);

use Modules\Core\Exceptions\MissingInstitutionContextException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

function ctx(): CurrentInstitution
{
    return app(CurrentInstitution::class);
}

it('consultar un modelo de dominio SIN contexto falla ruidoso', function () {
    ctx()->forget();

    expect(fn () => Contact::query()->count())
        ->toThrow(MissingInstitutionContextException::class);
});

it('crear un modelo de dominio SIN contexto falla ruidoso', function () {
    ctx()->forget();

    expect(fn () => Contact::query()->create([
        'first_name' => 'X',
        'email' => 'x@example.com',
    ]))->toThrow(MissingInstitutionContextException::class);
});

it('el modo global explicito permite operar sin institucion', function () {
    Institution::factory()->create();
    ctx()->forget();

    $count = ctx()->runGlobally(fn () => Contact::query()->count());

    expect($count)->toBe(0); // no lanza; opera cross-tenant de forma deliberada
});

it('la Institution NO esta sujeta al scope (es la raiz del tenant)', function () {
    ctx()->forget();

    // Institution no usa BelongsToInstitution: se puede consultar sin contexto.
    Institution::factory()->count(2)->create();

    expect(Institution::query()->count())->toBe(2);
});
