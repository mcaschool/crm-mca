<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Form;
use Modules\Catalog\Livewire\Programs\Index;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Services\CatalogImporter;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Las áreas (program_categories) solo existen dentro de la línea Microcredenciales:
 * formulario, importador y listado del catálogo. Más la migración de datos que crea el área
 * «Recursos Humanos» y le mueve 9 programas (up/down, idempotencia, institución).
 */
function areasInstitution(): Institution
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return $inst;
}

function rhMigration(): object
{
    return require base_path('Modules/Catalog/database/migrations/2026_09_30_110000_move_programs_to_recursos_humanos_area.php');
}

/** Ejecuta $test con una BD SQLite en memoria como conexión por defecto (el DDL en MySQL cerraría la transacción de la prueba). */
function withAreaSqlite(Closure $test): void
{
    config(['database.connections.area_mig' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $previous = config('database.default');
    DB::purge('area_mig');
    config(['database.default' => 'area_mig']);

    try {
        Schema::create('program_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('institution_id');
            $t->string('name_es', 120);
            $t->string('name_en', 120)->nullable();
            $t->string('slug', 80);
            $t->smallInteger('display_order')->default(0);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['institution_id', 'slug']);
        });
        Schema::create('programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('institution_id');
            $t->string('code', 40);
            $t->unsignedBigInteger('category_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('bot_program', function (Blueprint $t) {
            $t->unsignedBigInteger('bot_id');
            $t->unsignedBigInteger('program_id');
        });

        $test();
    } finally {
        config(['database.default' => $previous]);
        DB::purge('area_mig');
    }
}

/**
 * Siembra una institución con los 9 códigos en el área «Administración y Negocios» (menos
 * $skip) y otros programas que no se mueven. Devuelve el id de esa área.
 *
 * @param  array<int, string>  $skip
 */
function seedRhInstitution(int $inst, array $skip = []): int
{
    $admin = DB::table('program_categories')->insertGetId(['institution_id' => $inst, 'name_es' => 'Administración y Negocios', 'slug' => 'adm-'.$inst]);
    $lider = DB::table('program_categories')->insertGetId(['institution_id' => $inst, 'name_es' => 'Liderazgo', 'slug' => 'lid-'.$inst]);

    $codes = array_diff(['MC-002', 'MC-008', 'MC-010', 'MC-013', 'MC-014', 'MC-016', 'MC-019', 'MC-020', 'MC-056'], $skip);
    foreach ($codes as $code) {
        DB::table('programs')->insert(['institution_id' => $inst, 'code' => $code, 'category_id' => $code === 'MC-056' ? $lider : $admin]);
    }
    DB::table('programs')->insert(['institution_id' => $inst, 'code' => 'MC-001', 'category_id' => $admin]);
    DB::table('programs')->insert(['institution_id' => $inst, 'code' => 'PE-002', 'category_id' => $admin]);

    return $admin;
}

/** @return array<string, int|null> code => category_id */
function areaState(int $inst): array
{
    return DB::table('programs')->where('institution_id', $inst)->orderBy('code')->pluck('category_id', 'code')
        ->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

function rhAreaId(int $inst): ?int
{
    $id = DB::table('program_categories')->where('institution_id', $inst)->where('name_es', 'Recursos Humanos')->value('id');

    return $id === null ? null : (int) $id;
}

// --- Migración de datos «Recursos Humanos» ------------------------------------

it('migración RH: up crea el área en cada institución y mueve exactamente los 9 programas; bot_program intacto', function () {
    withAreaSqlite(function () {
        $admin1 = seedRhInstitution(1);
        seedRhInstitution(2);
        DB::table('program_categories')->insert(['institution_id' => 3, 'name_es' => 'Liderazgo', 'slug' => 'lid-3']); // institución sin esos programas
        DB::table('bot_program')->insert(['bot_id' => 7, 'program_id' => 1]);
        $before1 = areaState(1);

        rhMigration()->up();

        $rh1 = rhAreaId(1);
        $rh2 = rhAreaId(2);
        expect($rh1)->not->toBeNull()->and($rh2)->not->toBeNull()->and($rh1)->not->toBe($rh2)
            ->and(rhAreaId(3))->toBeNull()                                               // no se crea donde no hay programas
            ->and(DB::table('program_categories')->where('id', $rh1)->value('name_en'))->toBe('Human Resources');

        $after1 = areaState(1);
        expect(collect($after1)->filter(fn ($c) => $c === $rh1)->count())->toBe(9)
            ->and($after1['MC-001'])->toBe($admin1)->and($after1['PE-002'])->toBe($admin1) // el resto no se mueve
            ->and(collect(areaState(2))->filter(fn ($c) => $c === $rh2)->count())->toBe(9)  // cada una a SU área
            ->and(DB::table('program_area_moves')->where('institution_id', 1)->pluck('previous_category_id', 'program_code')->map(fn ($v) => (int) $v)->all())
            ->toEqual(collect($before1)->only(['MC-002', 'MC-008', 'MC-010', 'MC-013', 'MC-014', 'MC-016', 'MC-019', 'MC-020', 'MC-056'])->all())
            ->and(DB::table('bot_program')->get()->map(fn ($r) => (array) $r)->all())->toBe([['bot_id' => 7, 'program_id' => 1]]);
    });
});

it('migración RH: es idempotente (re-ejecutar no duplica área, respaldo ni movimientos)', function () {
    withAreaSqlite(function () {
        seedRhInstitution(1);
        $migration = rhMigration();

        $migration->up();
        $state = areaState(1);
        $backup = DB::table('program_area_moves')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $migration->up();

        expect(areaState(1))->toBe($state)
            ->and(DB::table('program_categories')->where('name_es', 'Recursos Humanos')->count())->toBe(1)
            ->and(DB::table('program_area_moves')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($backup);
    });
});

it('migración RH: si falta algún código falla sin cambios parciales', function () {
    withAreaSqlite(function () {
        seedRhInstitution(1);
        seedRhInstitution(2, skip: ['MC-020']);
        $before = [areaState(1), areaState(2)];
        $categories = DB::table('program_categories')->count();

        expect(fn () => rhMigration()->up())->toThrow(RuntimeException::class, 'faltan: MC-020');

        expect([areaState(1), areaState(2)])->toBe($before)
            ->and(DB::table('program_categories')->count())->toBe($categories)
            ->and(Schema::hasTable('program_area_moves'))->toBeFalse();
    });
});

it('migración RH: sin ninguno de los códigos (BD nueva) no hace nada', function () {
    withAreaSqlite(function () {
        DB::table('programs')->insert(['institution_id' => 1, 'code' => 'MC-001']);

        rhMigration()->up();

        expect(rhAreaId(1))->toBeNull()->and(Schema::hasTable('program_area_moves'))->toBeFalse();
        rhMigration()->down(); // tampoco falla al revertir
    });
});

it('migración RH: down devuelve cada programa a su área anterior, elimina el área vacía y el respaldo', function () {
    withAreaSqlite(function () {
        seedRhInstitution(1);
        seedRhInstitution(2);
        $before = [areaState(1), areaState(2)];
        $migration = rhMigration();

        $migration->up();
        $migration->down();

        expect([areaState(1), areaState(2)])->toBe($before)
            ->and(rhAreaId(1))->toBeNull()->and(rhAreaId(2))->toBeNull()
            ->and(Schema::hasTable('program_area_moves'))->toBeFalse();

        $migration->down(); // idempotente
        expect([areaState(1), areaState(2)])->toBe($before);
    });
});

it('migración RH: down conserva el área si otro programa la usa y no pisa cambios manuales', function () {
    withAreaSqlite(function () {
        $admin = seedRhInstitution(1);
        $migration = rhMigration();
        $migration->up();
        $rh = rhAreaId(1);

        DB::table('programs')->where('code', 'MC-001')->update(['category_id' => $rh]);   // añadido a mano a RH
        $liderazgo = (int) DB::table('program_categories')->where('name_es', 'Liderazgo')->value('id');
        DB::table('programs')->where('code', 'MC-008')->update(['category_id' => $liderazgo]); // movido a mano fuera

        $migration->down();

        $state = areaState(1);
        expect(rhAreaId(1))->toBe($rh)                          // no quedó vacía: se conserva
            ->and($state['MC-001'])->toBe($rh)
            ->and($state['MC-008'])->toBe($liderazgo)           // cambio manual respetado
            ->and($state['MC-002'])->toBe($admin);              // el resto vuelve a su área
    });
});

// --- Formulario: el Área solo en Microcredenciales --------------------------------

it('formulario: el campo Área solo se muestra y se exige en Microcredenciales', function () {
    $inst = areasInstitution();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $program = Program::factory()->create(['code' => 'MC-700', 'line' => 'microcredenciales', 'category_id' => null]);

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program])
        ->assertSeeHtml('wire:model="category_id"')
        ->call('save')->assertHasErrors(['category_id' => 'required'])
        ->set('category_id', $area->id)->call('save')->assertHasNoErrors();
    expect($program->fresh()->category_id)->toBe($area->id);

    // Al cambiar a otra línea el campo desaparece (en vivo) y deja de exigirse.
    Livewire::actingAs($admin)->test(Form::class)
        ->set('line', 'microcredenciales')->assertSeeHtml('wire:model="category_id"')
        ->set('line', 'programas_ejecutivos')->assertDontSeeHtml('wire:model="category_id"')
        ->set('code', 'PE-700')->set('name_es', 'Ejecutivo')->set('url', 'https://x.test/pe-700')
        ->call('save')->assertHasNoErrors();
    expect(Program::query()->where('code', 'PE-700')->value('category_id'))->toBeNull();
});

it('formulario: en otra línea no se muestra el Área y guardar no toca el category_id existente', function () {
    $inst = areasInstitution();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $program = Program::factory()->create(['code' => 'DA-700', 'line' => 'diplomas_avanzados', 'category_id' => $area->id]);

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program])
        ->assertDontSeeHtml('wire:model="category_id"')
        ->set('category_id', null)                     // aunque llegara un valor, fuera de MC no se aplica
        ->set('name_es', 'Diploma renombrado')->call('save')->assertHasNoErrors();

    expect($program->fresh()->name_es)->toBe('Diploma renombrado')->and($program->fresh()->category_id)->toBe($area->id);
});

// --- Importador: la columna Area solo cuenta en Microcredenciales -------------------

it('catalog:import aplica el Area solo en Microcredenciales y la ignora en el resto de líneas', function () {
    areasInstitution();
    $kept = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    Program::factory()->create(['code' => 'PE-801', 'line' => 'programas_ejecutivos', 'category_id' => $kept->id]);

    $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Catalogo');
    $writer->addRow(Row::fromValues(['ID', 'Nombre del programa', 'Tipo', 'Area', 'Descripcion', 'Etiquetas', 'URL', 'Activo']));
    $tags = 'nivel-intermedio, meta-ascenso, perfil-directivo';
    foreach ([
        ['MC-801', 'Micro', 'Microcredencial', 'Recursos Humanos'],
        ['PE-801', 'Ejecutivo existente', 'Programa Ejecutivo', 'Area Ejecutiva'],
        ['DA-801', 'Diploma', 'Diploma Avanzado', 'Area Diploma'],
        ['NT-801', 'Sin tipo', '', 'Area Sin Tipo'],
    ] as [$code, $name, $type, $area]) {
        $writer->addRow(Row::fromValues([$code, $name, $type, $area, 'Desc.', $tags, 'https://x.test/'.$code, 'TRUE']));
    }
    $writer->close();

    app(CatalogImporter::class)->import($path);
    @unlink($path);

    $byCode = Program::query()->with('category')->get()->keyBy('code');
    expect($byCode['MC-801']->category?->name_es)->toBe('Recursos Humanos')
        ->and($byCode['PE-801']->category_id)->toBe($kept->id)   // ignorada: no se toca lo guardado
        ->and($byCode['DA-801']->category_id)->toBeNull()
        ->and($byCode['NT-801']->category_id)->toBeNull()        // sin línea: tampoco es Microcredenciales
        ->and(ProgramCategory::query()->pluck('name_es')->sort()->values()->all())->toBe(['Liderazgo', 'Recursos Humanos']); // no se crean áreas fuera de MC
});

// --- Listado del catálogo ------------------------------------------------------------

it('el listado del catálogo solo muestra el área de los programas de Microcredenciales', function () {
    $inst = areasInstitution();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $mcArea = ProgramCategory::factory()->create(['name_es' => 'Area De Micro']);
    $peArea = ProgramCategory::factory()->create(['name_es' => 'Area Heredada PE']);
    Program::factory()->create(['code' => 'MC-900', 'line' => 'microcredenciales', 'category_id' => $mcArea->id]);
    Program::factory()->create(['code' => 'PE-900', 'line' => 'programas_ejecutivos', 'category_id' => $peArea->id]);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertSee('Area De Micro')->assertDontSee('Area Heredada PE');
});
