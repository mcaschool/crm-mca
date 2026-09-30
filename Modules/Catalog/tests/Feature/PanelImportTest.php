<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Import;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Models\ProgramLine;
use Modules\Catalog\Services\PanelCatalogImporter;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Fase 3 — Importador de catálogo desde el panel. Upsert por course_id, categoría de
 * formación auto-creable, sin tocar áreas (category_id), code no obligatorio, gating Admin.
 */
function importUser(string $role, Institution $inst): User
{
    $u = User::factory()->create(['institution_id' => $inst->id, 'role' => $role, 'status' => 'active']);
    app(CurrentInstitution::class)->set($inst->id);

    return $u;
}

/** Escribe un CSV temporal y devuelve su ruta. */
function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $content);

    return $path;
}

const IMP_CSV = "course_id,Nombre del Programa,Categoria\n"
    ."mgecc,Nombre Nuevo,Microcredenciales\n"
    ."mnuevo,Programa Nuevo,Programas Ejecutivos\n"
    .",Sin Course Id,Microcredenciales\n";

/** Programa existente (mgecc) + su categoría de formación y su ÁREA temática. */
function seedExistingProgram(Institution $inst): array
{
    return app(CurrentInstitution::class)->runFor($inst->id, function () {
        $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
        $micro = ProgramLine::factory()->create(['name_es' => 'Microcredenciales', 'slug' => 'microcredenciales']);
        $p = Program::factory()->create([
            'course_idnumber' => 'mgecc', 'name_es' => 'Nombre Viejo',
            'category_id' => $area->id, 'line_id' => $micro->id, 'code' => 'MC-012',
        ]);

        return ['area' => $area->id, 'program' => $p->id];
    });
}

it('la vista previa clasifica bien: crea, actualiza (con cambios), categoría nueva y error', function () {
    $inst = Institution::factory()->create();
    seedExistingProgram($inst);

    $plan = app(CurrentInstitution::class)->runFor($inst->id, function () {
        $importer = app(PanelCatalogImporter::class);

        return $importer->analyze($importer->parse(csvFile(IMP_CSV), 'csv'));
    });

    expect($plan['counts'])->toMatchArray(['create' => 1, 'update' => 1, 'new_categories' => 1, 'error' => 1]);
    expect($plan['creates'][0]['course_id'])->toBe('mnuevo');
    expect($plan['updates'][0]['course_id'])->toBe('mgecc');
    expect($plan['updates'][0]['changes']['name'])->toMatchArray(['from' => 'Nombre Viejo', 'to' => 'Nombre Nuevo']);
    expect($plan['new_categories'])->toBe(['Programas Ejecutivos']);
    expect($plan['errors'][0]['reason'])->toBe('course_id vacío');
});

it('aplica el upsert, crea la categoría nueva y NO toca el área (category_id); idempotente', function () {
    $inst = Institution::factory()->create();
    $ids = seedExistingProgram($inst);

    // 1ª aplicación.
    $res = app(CurrentInstitution::class)->runFor($inst->id, function () {
        $importer = app(PanelCatalogImporter::class);

        return $importer->apply($importer->parse(csvFile(IMP_CSV), 'csv'));
    });
    expect($res)->toMatchArray(['created' => 1, 'updated' => 1, 'new_categories' => 1, 'skipped' => 1]);

    app(CurrentInstitution::class)->runFor($inst->id, function () use ($ids) {
        expect(Program::query()->count())->toBe(2);
        // Actualizado: nombre nuevo, ÁREA intacta.
        $mgecc = Program::query()->whereRaw('LOWER(course_idnumber)=?', ['mgecc'])->firstOrFail();
        expect($mgecc->name_es)->toBe('Nombre Nuevo');
        expect($mgecc->category_id)->toBe($ids['area']);      // área NO tocada
        // Creado: code NULL (no inventado), categoría de formación = Programas Ejecutivos.
        $nuevo = Program::query()->whereRaw('LOWER(course_idnumber)=?', ['mnuevo'])->firstOrFail();
        expect($nuevo->code)->toBeNull();
        expect($nuevo->line->name_es)->toBe('Programas Ejecutivos');
        expect(ProgramLine::query()->count())->toBe(2);       // Micro + Ejecutivos
    });

    // 2ª aplicación del MISMO archivo → no duplica.
    app(CurrentInstitution::class)->runFor($inst->id, function () {
        $importer = app(PanelCatalogImporter::class);
        $importer->apply($importer->parse(csvFile(IMP_CSV), 'csv'));

        expect(Program::query()->count())->toBe(2);           // 2, nunca 4
        expect(ProgramLine::query()->where('slug', 'like', 'programas-ejecutivos%')->count())->toBe(1);
    });
});

it('el importador de panel es SOLO Admin (Marketing y Admisiones → 403)', function () {
    $inst = Institution::factory()->create();

    test()->actingAs(importUser('admin', $inst))->get('/catalog/import')->assertOk();
    test()->actingAs(importUser('marketing', $inst))->get('/catalog/import')->assertForbidden();
    test()->actingAs(importUser('admissions', $inst))->get('/catalog/import')->assertForbidden();
});

it('flujo del componente: subir → vista previa → confirmar aplica', function () {
    $inst = Institution::factory()->create();
    seedExistingProgram($inst);
    test()->actingAs(importUser('admin', $inst));

    Livewire::test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('programas.csv', IMP_CSV))
        ->assertSet('stage', 'preview')
        ->assertSet('preview.counts.create', 1)
        ->assertSet('preview.counts.update', 1)
        ->call('confirm')
        ->assertSet('stage', 'done')
        ->assertSet('result.created', 1)
        ->assertSet('result.updated', 1);

    app(CurrentInstitution::class)->runFor($inst->id, fn () => expect(Program::query()->count())->toBe(2));
});
