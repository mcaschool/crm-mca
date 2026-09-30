<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Models\ProgramLine;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Fase 2 — line_id (categoría de formación) + backfill. Relación con ProgramLine, backfill
 * idempotente y no destructivo (no toca category_id/course_idnumber/code).
 */
it('relaciona Program con su categoría de formación (line) sin romper el área (category)', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, function () {
        $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
        $line = ProgramLine::factory()->create(['name_es' => 'Microcredenciales']);
        $program = Program::factory()->create(['category_id' => $area->id, 'line_id' => $line->id]);

        expect($program->line->name_es)->toBe('Microcredenciales');   // categoría de formación
        expect($program->category->name_es)->toBe('Liderazgo');       // área temática (intacta)
        expect($line->programs()->pluck('id')->all())->toContain($program->id);
    });
});

it('el backfill crea «Microcredenciales» y asigna line_id a los programas sin línea (idempotente)', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($inst->id, function () {
        Program::factory()->count(3)->create(['line_id' => null]);
    });

    // 1ª corrida: crea la línea y asigna los 3.
    test()->artisan('catalog:backfill-lines', ['--institution' => $inst->id])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        $micro = ProgramLine::query()->where('slug', 'microcredenciales')->first();
        expect($micro)->not->toBeNull();
        expect(Program::query()->whereNull('line_id')->count())->toBe(0);
        expect(Program::query()->where('line_id', $micro->id)->count())->toBe(3);
    });

    // 2ª corrida: idempotente (no duplica la línea ni reasigna).
    test()->artisan('catalog:backfill-lines', ['--institution' => $inst->id])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($inst->id, function () {
        expect(ProgramLine::query()->where('slug', 'microcredenciales')->count())->toBe(1);
        expect(Program::query()->count())->toBe(3);          // 3, nunca 6
        expect(Program::query()->whereNull('line_id')->count())->toBe(0);
    });
});

it('el backfill NO reasigna programas que ya tienen otra categoría de formación', function () {
    $inst = Institution::factory()->create();
    $otherLineId = app(CurrentInstitution::class)->runFor($inst->id, function () {
        $other = ProgramLine::factory()->create(['name_es' => 'Programas Ejecutivos']);
        Program::factory()->create(['line_id' => $other->id]);   // ya asignado a otra línea
        Program::factory()->create(['line_id' => null]);          // sin línea

        return $other->id;
    });

    test()->artisan('catalog:backfill-lines', ['--institution' => $inst->id])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($inst->id, function () use ($otherLineId) {
        // El de «Programas Ejecutivos» se mantiene; solo el nulo pasó a Microcredenciales.
        expect(Program::query()->where('line_id', $otherLineId)->count())->toBe(1);
        $micro = ProgramLine::query()->where('slug', 'microcredenciales')->first();
        expect(Program::query()->where('line_id', $micro->id)->count())->toBe(1);
    });
});

it('el backfill NO altera category_id, course_idnumber ni code', function () {
    $inst = Institution::factory()->create();
    [$before, $id] = app(CurrentInstitution::class)->runFor($inst->id, function () {
        $area = ProgramCategory::factory()->create();
        $p = Program::factory()->create(['category_id' => $area->id, 'course_idnumber' => 'mgecc', 'code' => 'MC-012', 'line_id' => null]);

        return [['cat' => $p->category_id, 'idn' => $p->course_idnumber, 'code' => $p->code], $p->id];
    });

    test()->artisan('catalog:backfill-lines', ['--institution' => $inst->id])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($inst->id, function () use ($before, $id) {
        $p = Program::query()->findOrFail($id);
        expect($p->category_id)->toBe($before['cat']);
        expect($p->course_idnumber)->toBe($before['idn']);
        expect($p->code)->toBe($before['code']);
        expect($p->line_id)->not->toBeNull();   // sí ganó categoría de formación
    });
});
