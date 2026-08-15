<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * i18n de CONTENIDO: columnas _es/_en resueltas por el idioma activo, con
 * respaldo a espanol cuando falta la traduccion.
 */
beforeEach(function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
});

it('devuelve el valor del idioma activo', function () {
    $program = Program::factory()->create([
        'name_es' => 'Analitica de Datos',
        'name_en' => 'Data Analytics',
    ]);

    app()->setLocale('es');
    expect($program->name)->toBe('Analitica de Datos');

    app()->setLocale('en');
    expect($program->name)->toBe('Data Analytics');
});

it('cae al espanol cuando falta la traduccion en ingles', function () {
    $program = Program::factory()->create([
        'name_es' => 'Solo en espanol',
        'name_en' => null,
    ]);

    app()->setLocale('en');
    expect($program->name)->toBe('Solo en espanol');
    expect($program->isMissingTranslation('name', 'en'))->toBeTrue();
});

it('asigna al idioma activo escribiendo el atributo logico', function () {
    app()->setLocale('en');
    $program = Program::factory()->create(['name_es' => 'Base', 'name_en' => null]);

    $program->name = 'Written In English';
    $program->save();

    expect($program->fresh()->name_en)->toBe('Written In English');
    expect($program->fresh()->name_es)->toBe('Base');
});

it('ordena por el campo traducido del idioma activo', function () {
    Program::factory()->create(['name_es' => 'Zeta', 'name_en' => 'Alpha']);
    Program::factory()->create(['name_es' => 'Alfa', 'name_en' => 'Zulu']);

    app()->setLocale('en');
    $names = Program::query()->orderByTranslated('name')->get()
        ->map(fn (Program $p) => $p->name)->all();

    expect($names)->toBe(['Alpha', 'Zulu']);
});
