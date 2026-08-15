<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Catalog\Services\CatalogImporter;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Importador del catalogo: idempotencia, parseo de etiquetas y calidad de datos.
 */
function writeCatalogFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Catálogo');

    $writer->addRow(Row::fromValues([
        'ID', 'Nombre', 'Microcredencial que otorga', 'Area', 'Duracion', 'Modalidad',
        'Descripcion', 'URL', 'Activo', 'Peso', 'Etiquetas', 'Aprendizajes',
    ]));

    $rows = [
        ['MC-001', 'Analitica de Datos', 'Data Analytics Micro', 'Tecnologia', '6 semanas / 6 weeks', 'En linea / Online', 'Aprende analitica.', 'https://x/mc-001', 'Sí', 10, 'nivel-inicial, meta-actualizar, perfil-tecnico, dominante-datos, tema-excel', 'no importar'],
        ['MC-002', 'Gestion de Proyectos', 'Project Mgmt', 'Negocios', '8 semanas / 8 weeks', 'En linea / Online', 'Gestiona proyectos.', 'https://x/mc-002', 'Sí', 20, 'nivel-intermedio, meta-ascenso, perfil-gestor, tema-scrum', 'xx'],
        ['MC-011', 'Programa Incompleto', '', '', '', '', '', '', 'Sí', 30, '', ''],
        ['MC-056', 'Programa Sin Estado', 'Cred', 'Tecnologia', '4 semanas', 'En linea', 'Con estado en blanco.', 'https://x/mc-056', '', 60, 'nivel-avanzado, meta-especializar', ''],
        ['', 'Fila sin id', '', '', '', '', '', '', 'Sí', 99, '', ''],
    ];

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
}

function importInInstitution(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    $path = writeCatalogFixture();
    $report = app(CatalogImporter::class)->import($path);
    @unlink($path);

    return [$institution, $report];
}

it('importa creando y omite la fila sin ID', function () {
    [$institution, $report] = importInInstitution();

    // 4 programas con ID (MC-001, 002, 011, 056); la fila sin ID se omite.
    expect(Program::query()->withTrashed()->count())->toBe(4);
    expect($report->created)->toBe(4);
    expect($report->updated)->toBe(0);
    expect($report->skipped)->toHaveCount(1);
    expect($report->skipped[0]['reason'])->toContain('sin ID');
});

it('es idempotente: re-ejecutar actualiza, no duplica', function () {
    [$institution] = importInInstitution();

    // Segunda pasada sobre la misma institucion.
    app(CurrentInstitution::class)->set($institution->id);
    $path = writeCatalogFixture();
    $report2 = app(CatalogImporter::class)->import($path);
    @unlink($path);

    expect(Program::query()->withTrashed()->count())->toBe(4);
    expect($report2->created)->toBe(0);
    expect($report2->updated)->toBe(4);
});

it('parsea las etiquetas a level/goal/profile y el resto a program_tags', function () {
    [$institution] = importInInstitution();

    $mc001 = Program::query()->where('code', 'MC-001')->first();
    expect($mc001->level)->toBe('inicial');
    expect($mc001->goal)->toBe('actualizar');
    expect($mc001->profile)->toBe('tecnico');

    $tags = $mc001->tags()->pluck('tag')->all();
    expect($tags)->toContain('dominante-datos');
    expect($tags)->toContain('tema-excel');
    // Las estructurales NO quedan como program_tags.
    expect($tags)->not->toContain('nivel-inicial');
    expect($tags)->not->toContain('meta-actualizar');
});

it('separa duracion y modalidad bilingues', function () {
    [$institution] = importInInstitution();

    $mc001 = Program::query()->where('code', 'MC-001')->first();
    expect($mc001->duration_es)->toBe('6 semanas');
    expect($mc001->duration_en)->toBe('6 weeks');
    expect($mc001->modality_es)->toBe('En linea');
    expect($mc001->modality_en)->toBe('Online');
});

it('marca inactivas y reporta las filas incompletas y la de estado en blanco', function () {
    [$institution, $report] = importInInstitution();

    // MC-011 incompleta -> inactiva.
    $mc011 = Program::query()->where('code', 'MC-011')->first();
    expect($mc011->status)->toBe('inactive');

    // MC-056 estado en blanco -> inactiva.
    $mc056 = Program::query()->where('code', 'MC-056')->first();
    expect($mc056->status)->toBe('inactive');

    // Ambas aparecen en el reporte de incompletos.
    $reported = collect($report->incomplete)->pluck('code')->all();
    expect($reported)->toContain('MC-011');
    expect($reported)->toContain('MC-056');
});

it('deja name_en vacio para completar en el panel', function () {
    [$institution] = importInInstitution();

    $mc001 = Program::query()->where('code', 'MC-001')->first();
    expect($mc001->name_en)->toBeNull();
    // credential_en si se importa (punto de partida para el ingles).
    expect($mc001->credential_en)->toBe('Data Analytics Micro');
});
