<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Form;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Services\CatalogImporter;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * programs.line: línea de formación con los MISMOS slugs que el Centro de Conocimiento
 * (config crm.knowledge.lines). Migración con relleno por prefijo, importador por «Tipo»,
 * validación del slug y formulario.
 */
function lineMigration(): object
{
    return require base_path('Modules/Catalog/database/migrations/2026_09_30_100000_add_line_to_programs.php');
}

function lineInstitution(): Institution
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return $inst;
}

// --- Migración -----------------------------------------------------------------

it('migración: up añade programs.line indexada y rellena por prefijo; down la elimina', function () {
    // DDL aislado en SQLite en memoria (el DDL en MySQL cerraría la transacción de la prueba).
    config(['database.connections.line_mig' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $previous = config('database.default');
    DB::purge('line_mig');
    config(['database.default' => 'line_mig']);

    try {
        Schema::create('programs', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('programs')->insert([
            ['code' => 'MC-001'], ['code' => 'PE-003'], ['code' => 'DA-016'], ['code' => 'XX-9'], ['code' => 'mc-lower'],
        ]);

        $migration = lineMigration();
        $migration->up();

        expect(Schema::hasColumn('programs', 'line'))->toBeTrue()
            ->and(collect(Schema::getIndexes('programs'))->contains(fn ($i) => $i['columns'] === ['line']))->toBeTrue()
            ->and(DB::table('programs')->pluck('line', 'code')->all())->toBe([
                'MC-001' => 'microcredenciales',
                'PE-003' => 'programas_ejecutivos',
                'DA-016' => 'diplomas_avanzados',
                'XX-9' => null,
                'mc-lower' => 'microcredenciales', // LIKE no distingue mayúsculas (igual que MySQL con collation ci)
            ]);

        $migration->down();
        expect(Schema::hasColumn('programs', 'line'))->toBeFalse();
    } finally {
        config(['database.default' => $previous]);
        DB::purge('line_mig');
    }
});

it('relleno por prefijo: MC-/PE-/DA- con su línea, el resto NULL y reportado; idempotente', function () {
    lineInstitution();
    $mc = Program::factory()->create(['code' => 'MC-101']);
    $pe = Program::factory()->create(['code' => 'PE-101']);
    $da = Program::factory()->create(['code' => 'DA-101']);
    $other = Program::factory()->create(['code' => 'ZZ-101']);
    DB::table('programs')->update(['line' => null]);

    $report = lineMigration()->backfill();

    expect($mc->fresh()->line)->toBe('microcredenciales')
        ->and($pe->fresh()->line)->toBe('programas_ejecutivos')
        ->and($da->fresh()->line)->toBe('diplomas_avanzados')
        ->and($other->fresh()->line)->toBeNull()
        ->and($report['unassigned'])->toContain('ZZ-101')
        ->and($report['assigned'])->toMatchArray(['microcredenciales' => 1, 'programas_ejecutivos' => 1, 'diplomas_avanzados' => 1]);

    expect(lineMigration()->backfill()['assigned'])->toBe(['microcredenciales' => 0, 'programas_ejecutivos' => 0, 'diplomas_avanzados' => 0]);
});

// --- Validación del slug -----------------------------------------------------

it('programs.line solo acepta slugs de knowledge.lines (o null)', function () {
    lineInstitution();

    expect(Program::factory()->create(['line' => 'maestrias'])->line)->toBe('maestrias')
        ->and(Program::factory()->create(['line' => null])->line)->toBeNull();

    expect(fn () => Program::factory()->create(['line' => 'cursos_libres']))->toThrow(InvalidArgumentException::class);
});

it('el formulario de programa guarda la línea y rechaza una que no está en la lista', function () {
    $inst = lineInstitution();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $program = Program::factory()->create(['code' => 'DA-555', 'line' => null]);

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program])
        ->set('line', 'cursos_libres')->call('save')->assertHasErrors(['line' => 'in']);

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program])
        ->assertSet('line', '')
        ->set('line', 'diplomas_avanzados')->call('save')->assertHasNoErrors();
    expect($program->fresh()->line)->toBe('diplomas_avanzados');

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program->fresh()])
        ->assertSet('line', 'diplomas_avanzados')
        ->set('line', '')->call('save')->assertHasNoErrors();
    expect($program->fresh()->line)->toBeNull();
});

// --- Importador: columna «Tipo» -----------------------------------------------

it('catalog:import asigna la línea desde «Tipo» y reporta los tipos desconocidos (line NULL)', function () {
    lineInstitution();
    $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Catalogo');
    $writer->addRow(Row::fromValues(['ID', 'Nombre del programa', 'Tipo', 'Area', 'Descripcion', 'Etiquetas', 'URL', 'Activo']));
    $tags = 'nivel-intermedio, meta-ascenso, perfil-directivo';
    foreach ([
        ['MC-901', 'Micro', 'Microcredencial'],
        ['PE-901', 'Ejecutivo', 'Programa Ejecutivo'],
        ['DA-901', 'Diploma', 'Diploma Avanzado'],
        ['MA-901', 'Máster', 'Maestría'],
        ['XX-901', 'Curso libre', 'Curso libre'],
        ['NT-901', 'Sin tipo', ''],
    ] as [$code, $name, $type]) {
        $writer->addRow(Row::fromValues([$code, $name, $type, 'Liderazgo', 'Desc.', $tags, 'https://x.test/'.$code, 'TRUE']));
    }
    $writer->close();

    $report = app(CatalogImporter::class)->import($path);
    @unlink($path);

    expect(Program::query()->pluck('line', 'code')->all())->toMatchArray([
        'MC-901' => 'microcredenciales',
        'PE-901' => 'programas_ejecutivos',
        'DA-901' => 'diplomas_avanzados',
        'MA-901' => 'maestrias',
        'XX-901' => null,
        'NT-901' => null,
    ])->and($report->created)->toBe(6)
        ->and($report->unknownTypes)->toBe([['code' => 'XX-901', 'type' => 'Curso libre']]);
});

it('catalog:import acepta el slug interno en «Tipo» (micro_mba) igual que la etiqueta, y la línea Estancias', function () {
    lineInstitution();
    $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Catalogo');
    $writer->addRow(Row::fromValues(['ID', 'Nombre del programa', 'Tipo', 'Descripcion', 'Etiquetas', 'URL', 'Activo']));
    $tags = 'nivel-intermedio, meta-ascenso, perfil-directivo';
    foreach ([
        ['MMBA-1', 'Micro MBA por slug', 'micro_mba'],
        ['MMBA-2', 'Micro MBA por etiqueta', 'Micro MBA'],
        ['PE-1', 'Ejecutivo por slug', 'programas_ejecutivos'],
        ['EST-1', 'Estancia por slug', 'estancias'],
        ['EST-2', 'Estancia por etiqueta', 'Estancia'],
    ] as [$code, $name, $type]) {
        $writer->addRow(Row::fromValues([$code, $name, $type, 'Desc.', $tags, 'https://x.test/'.$code, 'TRUE']));
    }
    $writer->close();

    $report = app(CatalogImporter::class)->import($path);
    @unlink($path);

    expect(Program::query()->pluck('line', 'code')->all())->toMatchArray([
        'MMBA-1' => 'micro_mba',
        'MMBA-2' => 'micro_mba',
        'PE-1' => 'programas_ejecutivos',
        'EST-1' => 'estancias',
        'EST-2' => 'estancias',
    ])->and($report->unknownTypes)->toBe([]);
});
