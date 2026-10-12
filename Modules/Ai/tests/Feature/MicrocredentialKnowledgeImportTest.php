<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\KnowledgeRetriever;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Ai\Services\MicrocredentialKnowledgeImporter;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Services\CatalogImporter;
use Modules\Catalog\Support\CatalogSheet;
use Modules\Chat\Services\MatcherService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Conocimiento académico de las microcredenciales desde el Excel del catálogo
 * (MicrocredentialKnowledgeImporter / catalog:import-knowledge): una fuente «programa_academico»
 * por programa por el pipeline de la Biblioteca, sin tocar el recomendador. Excel temporal
 * controlado y, aparte, el catalogo.xlsx real del repositorio.
 */
const MKI_HEADER = ['ID', 'Nombre del programa', 'Tipo', 'Area', 'Microcredencial que otorga', 'Duracion', 'Modalidad', 'Descripcion', 'Aprendizajes', 'Etiquetas', 'URL', 'Activo', 'Peso'];
const MKI_REAL_EXCEL = 'storage/app/catalog/catalogo.xlsx';

/** Fila completa del Excel (orden de MKI_HEADER), con cambios opcionales por cabecera. */
function mkiRow(int $n, array $over = []): array
{
    $names = [1 => 'Dirección y Toma de Decisiones Organizacionales', 2 => 'Analítica de Datos para Negocios', 3 => 'Gestión Ágil de Proyectos', 4 => 'Marketing Digital Estratégico', 5 => 'Finanzas para No Financieros'];
    $code = sprintf('MC-%03d', $n);
    $name = $names[$n] ?? "Programa de Prueba {$n}";
    $row = [
        'ID' => $code,
        'Nombre del programa' => $name,
        'Tipo' => 'Microcredencial',
        'Area' => $n % 2 === 0 ? 'Tecnología' : 'Administración y Negocios',
        'Microcredencial que otorga' => "Credential {$n} Leader",
        'Duracion' => ($n + 3).' semanas',
        'Modalidad' => 'Online asincrónico',
        'Descripcion' => "El programa {$name} ofrece una formación orientada a resultados (descripción {$n}).\nSegundo párrafo completo de la descripción {$n}.",
        'Aprendizajes' => "• Comprender los fundamentos del tema {$n}.\n• Analizar casos reales del tema {$n}.\n• Aplicar herramientas del tema {$n} en la organización.\n• Diseñar un plan de mejora del tema {$n}, con indicadores y seguimiento.",
        'Etiquetas' => 'dominante-tema'.$n.', nivel-inicial, meta-ascenso, perfil-directivo',
        'URL' => "https://mcaschool.education/es/microcredenciales/programs/programa-{$n}/",
        'Activo' => 'TRUE',
        'Peso' => (string) $n,
    ];

    return array_values(array_merge($row, $over));
}

/** @param  list<array<int, string>>  $rows */
function mkiExcel(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'mki').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Catalogo');
    $writer->addRow(Row::fromValues(MKI_HEADER));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

/**
 * Institución con Celia (slug «microcredenciales», búsqueda clásica) y Sophia; el catálogo importado
 * del Excel y asignado a Celia (bot_program); la base general en la Biblioteca, asignada a Celia.
 *
 * @return array{0: Institution, 1: Bot, 2: Bot, 3: string}
 */
function mkiCtx(int $programs = 5, ?string $excel = null, bool $fresh = true, string $generalFile = 'celia_kb_general_es_ver2.1.md', ?string $generalContent = null): array
{
    if ($fresh) {
        Storage::fake('knowledge');   // el disco es UNO para toda la instalación (compartido)
    }
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $celia = Bot::factory()->create(['slug' => 'microcredenciales', 'assistant_name' => 'Celia', 'knowledge_retrieval' => Bot::RETRIEVAL_CLASSIC]);
    $sophia = Bot::factory()->create(['slug' => 'sophia', 'assistant_name' => 'Sophia']);

    $excel ??= mkiExcel(array_map(fn (int $n): array => mkiRow($n), range(1, $programs)));
    app(CatalogImporter::class)->import($excel);
    $celia->programs()->sync(Program::query()->where('line', 'microcredenciales')->pluck('id')->all());

    Storage::disk('knowledge')->put('biblioteca/microcredenciales/'.$generalFile, $generalContent ?? (string) file_get_contents(__DIR__.'/../Fixtures/kb_mc_general.md'));
    app(KnowledgeSyncService::class)->syncLibrary();
    app(KnowledgeAssignmentService::class)->assignSource($celia, KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->firstOrFail());

    return [$inst, $celia, $sophia, $excel];
}

function mkiImport(string $excel, ?Bot $advisor = null, bool $dryRun = false): array
{
    return app(MicrocredentialKnowledgeImporter::class)->import($excel, $advisor, $dryRun);
}

/** @return array<int, bool> source_id => is_active del asesor */
function mkiPivot(Bot $bot): array
{
    return DB::table('bot_knowledge_source')->where('bot_id', $bot->id)->pluck('is_active', 'knowledge_source_id')->map(fn ($v): bool => (bool) $v)->all();
}

it('1–9 · una fila completa: una fuente programa_academico de microcredenciales, con su programa y TODO el contenido del Excel', function () {
    [, , , $excel] = mkiCtx(1);
    $report = mkiImport($excel);

    $source = KnowledgeSource::query()->where('code', 'MC-001')->sole();
    $program = Program::query()->where('code', 'MC-001')->sole();
    $content = (string) $source->content_es;
    $row = array_combine(MKI_HEADER, mkiRow(1));

    expect($report['created'])->toBe(['MC-001'])
        ->and($source->name)->toBe('Dirección y Toma de Decisiones Organizacionales — Microcredencial')
        ->and($source->type)->toBe('programa_academico')
        ->and($source->category)->toBe('microcredenciales')
        ->and((int) $source->program_id)->toBe($program->id)
        ->and($source->priority)->toBe(3)
        ->and($source->status)->toBe('active')
        ->and($source->url)->toBeNull()                                  // nunca el enlace de la línea
        ->and($source->content_en)->toBeNull()                           // sin inglés inventado
        ->and(Storage::disk('knowledge')->exists('biblioteca/microcredenciales/institucion-'.app(CurrentInstitution::class)->id().'/MC-001.md'))->toBeTrue();

    expect($content)
        ->toContain('<!-- Codigo: MC-001 · Programa: MC-001 · Idioma: es · Categoria: microcredenciales · Prioridad: 3 · Institucion: '.app(CurrentInstitution::class)->id().' -->')
        ->toContain('## Descripción de Dirección y Toma de Decisiones Organizacionales')
        ->toContain('## Qué aprenderás en Dirección y Toma de Decisiones Organizacionales')
        ->toContain('## Información académica de Dirección y Toma de Decisiones Organizacionales')
        ->toContain('## Página oficial de Dirección y Toma de Decisiones Organizacionales')
        ->toContain($row['Descripcion'])                                 // descripción completa
        ->toContain("- Comprender los fundamentos del tema 1.\n- Analizar casos reales del tema 1.\n- Aplicar herramientas del tema 1 en la organización.\n- Diseñar un plan de mejora del tema 1, con indicadores y seguimiento.")
        ->toContain('- Área: Administración y Negocios')
        ->toContain('- Microcredencial que otorga: Credential 1 Leader')
        ->toContain('- Duración: 4 semanas')
        ->toContain('- Modalidad: Online asincrónico')
        ->toContain($program->url)
        ->not->toContain('•')
        ->not->toContain('nivel-')->not->toContain('meta-')->not->toContain('perfil-')->not->toContain('dominante-');

    // La URL individual sigue en programs.url.
    expect($program->fresh()->url)->toBe($row['URL']);
});

it('2–9 · el catalogo.xlsx real: 57 fichas, una por programa, con todos los aprendizajes completos', function () {
    $excel = base_path(MKI_REAL_EXCEL);
    [, $celia] = mkiCtx(excel: $excel);
    $report = mkiImport($excel, $celia);

    expect($report['created'])->toHaveCount(57)
        ->and($report['rejected'])->toBe([])
        ->and(KnowledgeSource::query()->where('type', 'programa_academico')->where('category', 'microcredenciales')->count())->toBe(57);

    foreach (CatalogSheet::rows($excel) as $row) {
        $f = $row['fields'];
        $source = KnowledgeSource::query()->where('code', $f['code'])->sole();
        expect((int) $source->program_id)->toBe(Program::query()->where('code', $f['code'])->value('id'))
            ->and((string) $source->content_es)->toContain(trim((string) preg_replace('/\R/u', "\n", $f['description'])))
            ->and((string) $source->content_es)->toContain(MicrocredentialKnowledgeImporter::bullets($f['learnings']))
            ->and((string) $source->content_es)->toContain($f['url'])
            ->and((string) $source->content_es)->toContain('- Área: '.$f['category']);
        // Ningún aprendizaje se pierde: tantas viñetas como líneas con contenido.
        $items = count(array_filter(preg_split('/\R/u', $f['learnings']) ?: [], fn ($l) => trim((string) preg_replace('/^\s*[•·▪◦‣∙\-\*–]\s*/u', '', (string) $l)) !== ''));
        expect(substr_count(MicrocredentialKnowledgeImporter::bullets($f['learnings']), "\n") + 1)->toBe($items);
    }

    // 21 · contadores: 58 DOCUMENTOS en Microcredenciales (57 fichas + 1 base) frente a 57 PROGRAMAS.
    $admin = User::factory()->create(['institution_id' => app(CurrentInstitution::class)->id(), 'role' => 'admin']);
    $byCategory = Livewire::actingAs($admin)->test(Library::class)->viewData('byCategory');
    expect((int) $byCategory['microcredenciales'])->toBe(58)
        ->and(Program::query()->where('line', 'microcredenciales')->where('status', 'active')->count())->toBe(57)
        ->and($celia->programs()->count())->toBe(57)
        ->and(count(array_filter(mkiPivot($celia))))->toBe(58);
});

it('10–11 · idempotente: la segunda importación no duplica; un cambio en el Excel actualiza solo esa ficha', function () {
    [, , , $excel] = mkiCtx(3);
    mkiImport($excel);
    $ids = KnowledgeSource::query()->orderBy('id')->pluck('id', 'code')->all();

    $again = mkiImport($excel);
    expect($again['created'])->toBe([])->and($again['updated'])->toBe([])
        ->and($again['unchanged'])->toBe(['MC-001', 'MC-002', 'MC-003'])
        ->and(KnowledgeSource::query()->orderBy('id')->pluck('id', 'code')->all())->toBe($ids)
        ->and(count(Storage::disk('knowledge')->allFiles('biblioteca/microcredenciales')))->toBe(4);

    $rows = [mkiRow(1), mkiRow(2, ['Aprendizajes' => "• Nuevo aprendizaje A.\n• Nuevo aprendizaje B."]), mkiRow(3)];
    $changed = mkiImport(mkiExcel($rows));
    expect($changed['updated'])->toBe(['MC-002'])->and($changed['unchanged'])->toBe(['MC-001', 'MC-003'])
        ->and((string) KnowledgeSource::query()->where('code', 'MC-002')->value('content_es'))->toContain("- Nuevo aprendizaje A.\n- Nuevo aprendizaje B.")->not->toContain('tema 2.')
        ->and(KnowledgeSource::query()->where('code', 'MC-002')->count())->toBe(1);
});

it('12–14 · código inexistente, programa inactivo y fila incompleta: se rechazan con su motivo y las demás filas siguen', function () {
    [, , , $excel] = mkiCtx(3);
    Program::query()->where('code', 'MC-003')->update(['status' => 'inactive']);

    $report = mkiImport(mkiExcel([
        mkiRow(1),
        mkiRow(2, ['Aprendizajes' => '', 'Duracion' => '']),     // incompleta
        mkiRow(3),                                                // programa inactivo
        mkiRow(99),                                               // no existe en programs
        mkiRow(4, ['ID' => '']),                                  // sin código
        mkiRow(5, ['Tipo' => 'Programa Ejecutivo']),              // no es microcredencial
    ]));

    $reasons = collect($report['rejected'])->pluck('reason', 'code');
    expect($report['created'])->toBe(['MC-001'])
        ->and($reasons['MC-002'])->toContain('duración')->toContain('aprendizajes')
        ->and($reasons['MC-003'])->toContain('inactivo')
        ->and($reasons['MC-099'])->toContain('no existe')
        ->and($reasons[''])->toContain('sin código')
        ->and($reasons['MC-005'])->toContain('no es una microcredencial')
        ->and(KnowledgeSource::query()->whereIn('code', ['MC-002', 'MC-003', 'MC-099', 'MC-005'])->count())->toBe(0);
});

it('13 · un programa que pasa a inactivo deja su ficha desactivada (no recuperable por Celia)', function () {
    [, $celia, , $excel] = mkiCtx(2);
    mkiImport($excel, $celia);
    Program::query()->where('code', 'MC-002')->update(['status' => 'inactive']);

    $report = mkiImport($excel, $celia);

    expect($report['deactivated'])->toBe(['MC-002'])
        ->and(KnowledgeSource::query()->where('code', 'MC-002')->value('status'))->toBe('inactive')
        ->and(app(KnowledgeRetriever::class)->retrieveWithSources($celia->id, '¿Qué aprendo en Analítica de Datos para Negocios?', 'es')['sources'])->not->toContain('MC-002');
});

it('decisiones manuales: una ficha desactivada o una asignación pausada en la Biblioteca no se reactivan al reimportar', function () {
    [, $celia, , $excel] = mkiCtx(3);
    mkiImport($excel, $celia);
    $paused = KnowledgeSource::query()->where('code', 'MC-002')->sole();
    $inactive = KnowledgeSource::query()->where('code', 'MC-003')->sole();
    app(KnowledgeAssignmentService::class)->toggle($celia, $paused, false);       // pausa del asesor
    $inactive->update(['status' => 'inactive']);                                  // pausa global

    $report = mkiImport(mkiExcel([mkiRow(1), mkiRow(2), mkiRow(3, ['Descripcion' => 'Descripción nueva del 3.'])]), $celia);

    expect($report['kept_inactive'])->toBe(['MC-003'])
        ->and($report['updated'])->toBe(['MC-003'])                                 // el contenido sí se actualiza
        ->and($inactive->fresh()->status)->toBe('inactive')
        ->and($report['kept_paused'])->toBe(['MC-002'])
        ->and(mkiPivot($celia)[$paused->id])->toBeFalse()
        ->and($report['assigned'])->toBe([]);
});

it('15 · aislamiento: los programas, fichas y asignaciones de otra institución no se ven ni se tocan', function () {
    [$instA] = mkiCtx(2);
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($instB->id);
    $botB = Bot::factory()->create(['slug' => 'microcredenciales']);

    // En B no existen los programas de A: todo rechazado, nada creado en ninguna institución.
    $report = mkiImport(mkiExcel([mkiRow(1), mkiRow(2)]), $botB);
    expect(collect($report['rejected'])->pluck('code')->all())->toBe(['MC-001', 'MC-002'])
        ->and($report['assigned'])->toBe([])
        ->and(KnowledgeSource::query()->count())->toBe(0);

    app(CurrentInstitution::class)->set($instA->id);
    expect(KnowledgeSource::query()->where('type', 'programa_academico')->count())->toBe(0)
        ->and(DB::table('bot_knowledge_source')->where('bot_id', $botB->id)->count())->toBe(0);
});

it('16 · --dry-run no escribe nada (ni archivos, ni fuentes, ni asignaciones, ni la base general) y muestra lo que haría', function () {
    [, $celia, , $excel] = mkiCtx(3);
    $files = Storage::disk('knowledge')->allFiles();
    $general = (string) Storage::disk('knowledge')->get('biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md');
    $sources = KnowledgeSource::query()->count();
    $pivot = mkiPivot($celia);

    $report = mkiImport($excel, $celia, dryRun: true);

    expect($report['dry_run'])->toBeTrue()
        ->and($report['created'])->toBe(['MC-001', 'MC-002', 'MC-003'])
        ->and($report['assigned'])->toBe(['MC-001', 'MC-002', 'MC-003'])
        ->and($report['general'])->toMatchArray(['status' => 'would_normalize', 'from' => 0, 'to' => 6, 'path' => 'biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md'])
        ->and(Storage::disk('knowledge')->allFiles())->toBe($files)
        ->and((string) Storage::disk('knowledge')->get('biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md'))->toBe($general)
        ->and(KnowledgeSource::query()->count())->toBe($sources)
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(0)
        ->and(mkiPivot($celia))->toBe($pivot);

    // El comando con --dry-run tampoco escribe.
    Artisan::call('catalog:import-knowledge', ['file' => $excel, '--institution' => app(CurrentInstitution::class)->id(), '--advisor' => 'microcredenciales', '--dry-run' => true]);
    expect(Artisan::output())->toContain('PREVISUALIZACIÓN')->toContain('Creadas:       3')->toContain('Se normalizaría la prioridad de 0 a 6')
        ->and(KnowledgeSource::query()->count())->toBe($sources);
});

it('17–19 · asignación idempotente a Celia, nunca a otros asesores, y la base general se conserva', function () {
    [, $celia, $sophia, $excel] = mkiCtx(4);
    $programs = $celia->programs()->pluck('programs.id')->sort()->values()->all();

    $first = mkiImport($excel, $celia);
    $second = mkiImport($excel, $celia);
    $general = KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->sole();

    expect($first['assigned'])->toBe(['MC-001', 'MC-002', 'MC-003', 'MC-004'])
        ->and($first['already_assigned'])->toBe(['KB-MC-GENERAL-001'])
        ->and($second['assigned'])->toBe([])
        ->and($second['already_assigned'])->toHaveCount(5)
        ->and(count(array_filter(mkiPivot($celia))))->toBe(5)
        ->and(DB::table('bot_knowledge_source')->where('bot_id', $celia->id)->count())->toBe(5)
        ->and(mkiPivot($sophia))->toBe([])                                         // 18
        ->and(mkiPivot($celia)[$general->id])->toBeTrue()                          // 19
        ->and($celia->programs()->pluck('programs.id')->sort()->values()->all())->toBe($programs);  // bot_program intacto
});

it('base general: la prioridad se normaliza a 6 en su ARCHIVO, se sincroniza y se informa; idempotente', function () {
    [, , , $excel] = mkiCtx(1);
    $path = 'biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md';

    $report = mkiImport($excel);
    $file = (string) Storage::disk('knowledge')->get($path);

    expect($report['general'])->toMatchArray(['status' => 'normalized', 'from' => 0, 'to' => 6, 'path' => $path])
        ->and($file)->toContain('<!-- Código: KB-MC-GENERAL-001 · Idioma: es · Fuente de conocimiento de Celia (hechos transversales) · Prioridad: 6 · Institucion: '.app(CurrentInstitution::class)->id().' -->')
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(6)
        ->and(mkiImport($excel)['general']['status'])->toBe('already')
        ->and((string) Storage::disk('knowledge')->get($path))->toBe($file);
});

it('base general ambigua o fuera de la Biblioteca: no se toca y se informa', function (string $case) {
    [, , , $excel] = mkiCtx(1);
    $general = 'biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md';
    $raw = (string) Storage::disk('knowledge')->get($general);
    if ($case === 'ambiguous') {
        Storage::disk('knowledge')->put('biblioteca/otra_linea/celia_kb_general_es_ver2.1.md', $raw);   // mismo nombre, dos sitios
    } else {
        Storage::disk('knowledge')->delete($general);
        Storage::disk('knowledge')->put('microcredenciales/celia_kb_general_es_ver2.1.md', $raw);
    }

    $report = mkiImport($excel);

    expect($report['general']['status'])->toBe($case === 'ambiguous' ? 'ambiguous' : 'outside_library')
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(0)
        ->and(collect(Storage::disk('knowledge')->allFiles())->map(fn ($f) => (string) Storage::disk('knowledge')->get($f))->filter(fn ($c) => str_contains($c, 'Prioridad: 6'))->count())->toBe(0);
})->with(['ambiguous', 'outside_library']);

it('20 · el recomendador conserva sus programas, asignaciones y resultados (con su degradación)', function () {
    [, $celia, , $excel] = mkiCtx(5);
    $matcher = app(MatcherService::class);
    $area = Program::query()->where('code', 'MC-002')->value('category_id');
    $cases = [
        ['area' => (string) $area, 'meta' => 'ascenso', 'seniority' => 'inicio', 'educacion' => 'secundaria'],     // nivel 1
        ['area' => (string) $area, 'meta' => 'ascenso', 'seniority' => 'directivo', 'educacion' => 'posgrado'],   // degrada
        ['area' => '', 'meta' => '', 'seniority' => 'inicio', 'educacion' => 'secundaria'],
    ];
    $snapshot = fn (): array => [
        array_map(fn (array $a): array => [($r = $matcher->match($celia, null, null, $a))->programs->pluck('code')->all(), $r->tier], $cases),
        Program::query()->orderBy('code')->get(['code', 'name_es', 'category_id', 'level', 'goal', 'profile', 'display_order', 'short_description_es', 'url', 'status'])->toArray(),
        DB::table('program_tags')->orderBy('program_id')->orderBy('tag')->get(['program_id', 'tag'])->toArray(),
        DB::table('bot_program')->orderBy('bot_id')->orderBy('program_id')->get(['bot_id', 'program_id'])->toArray(),
    ];
    $before = $snapshot();

    mkiImport($excel, $celia);

    expect($snapshot())->toEqual($before)
        ->and($before[0][0][0])->not->toBe([]);
});

it('22 · una sincronización posterior de la Biblioteca no borra ni desclasifica las fichas y conserva las prioridades 3 y 6', function () {
    [, $celia, , $excel] = mkiCtx(3);
    mkiImport($excel, $celia);
    $before = KnowledgeSource::query()->orderBy('code')->get(['code', 'type', 'category', 'program_id', 'status', 'priority'])->toArray();

    app(KnowledgeSyncService::class)->syncLibrary();

    expect(KnowledgeSource::query()->orderBy('code')->get(['code', 'type', 'category', 'program_id', 'status', 'priority'])->toArray())->toBe($before)
        ->and(KnowledgeSource::query()->where('type', 'programa_academico')->pluck('priority')->unique()->all())->toBe([3])
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(6)
        ->and(count(array_filter(mkiPivot($celia))))->toBe(4);
});

it('búsqueda clásica de Celia con 58 fuentes: las preguntas generales recuperan la base general', function (string $question) {
    $excel = base_path(MKI_REAL_EXCEL);
    [, $celia] = mkiCtx(excel: $excel);
    mkiImport($excel, $celia);

    $sources = app(KnowledgeRetriever::class)->retrieveWithSources($celia->id, $question, 'es')['sources'];

    expect($sources)->toContain('KB-MC-GENERAL-001');
})->with([
    'duración general' => '¿Cuánto duran las microcredenciales?',
    'certificación' => '¿Qué certificación recibo al terminar?',
    'formas de pago' => '¿Cuáles son las formas de pago?',
    'inscripción' => '¿Cómo es el proceso de inscripción?',
    'metodología' => '¿Cómo es la metodología de estudio?',
    'requisitos generales' => '¿Qué requisitos necesito para matricularme?',
]);

it('búsqueda clásica de Celia con 58 fuentes: las preguntas de un programa recuperan SU ficha, no otra', function (string $question, string $code) {
    $excel = base_path(MKI_REAL_EXCEL);
    [, $celia] = mkiCtx(excel: $excel);
    mkiImport($excel, $celia);

    $retrieved = app(KnowledgeRetriever::class)->retrieveWithSources($celia->id, $question, 'es');

    expect($retrieved['sources'][0] ?? null)->toBe($code)
        ->and(array_filter($retrieved['sources'], fn (string $s) => str_starts_with($s, 'MC-') && $s !== $code))->toBe([]);
})->with([
    'duración de MC-001' => ['¿Cuánto dura Dirección y Toma de Decisiones Organizacionales?', 'MC-001'],
    'qué se aprende' => ['¿Qué aprenderé en Dirección y Toma de Decisiones Organizacionales?', 'MC-001'],
    'modalidad' => ['¿Cuál es la modalidad de Dirección y Toma de Decisiones Organizacionales?', 'MC-001'],
    'enlace oficial' => ['Dame la página oficial de Dirección y Toma de Decisiones Organizacionales', 'MC-001'],
    'nombre parcial' => ['Información de toma de decisiones organizacionales', 'MC-001'],
]);

// --- Aislamiento entre instituciones en el disco de conocimiento COMPARTIDO -----------------------
// Cada ficha generada declara «Institucion: {id}» y vive en biblioteca/microcredenciales/
// institucion-{id}/: para cualquier otra institución no es candidata (syncLibrary, sync, búsqueda y
// borrado por código). La base general queda marcada al normalizarla.

/** Foto completa de una institución: fuentes (con contenido), sus archivos y sus asignaciones. */
function mkiSnapshot(Institution $inst): array
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn (): array => [
        'sources' => KnowledgeSource::query()->orderBy('code')->get(['id', 'code', 'name', 'type', 'category', 'program_id', 'priority', 'status', 'source_file', 'content_es', 'content_en'])->toArray(),
        'pivot' => DB::table('bot_knowledge_source')->join('bots', 'bots.id', '=', 'bot_knowledge_source.bot_id')->where('bots.institution_id', $inst->id)
            ->orderBy('bot_id')->orderBy('knowledge_source_id')->get(['bot_id', 'knowledge_source_id', 'is_active'])->toArray(),
        'files' => collect(Storage::disk('knowledge')->allFiles())
            ->filter(fn (string $f): bool => app(KnowledgeSyncService::class)->institutionOf((string) Storage::disk('knowledge')->get($f)) === (string) $inst->id)
            ->mapWithKeys(fn (string $f): array => [$f => md5((string) Storage::disk('knowledge')->get($f))])->sortKeys()->all(),
    ]);
}

it('aislamiento: las 57 fichas y la base general de A nunca son candidatas en B (syncLibrary, importación, asignación, borrado); A queda idéntica', function () {
    $excel = base_path(MKI_REAL_EXCEL);
    [$instA, $celiaA] = mkiCtx(excel: $excel);
    mkiImport($excel, $celiaA);

    // 3. A: sus 57 fichas + la base general (normalizada y marcada como de A), las 58 asignadas.
    expect(KnowledgeSource::query()->where('type', 'programa_academico')->count())->toBe(57)
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(6)
        ->and(count(array_filter(mkiPivot($celiaA))))->toBe(58);
    $before = mkiSnapshot($instA);
    expect($before['files'])->toHaveCount(58);

    // 4–6. B, completamente vacía, sincroniza la Biblioteca del MISMO disco: nada de A entra.
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($instB->id);
    $botB = Bot::factory()->create(['slug' => 'microcredenciales', 'assistant_name' => 'Celia B']);
    $sync = app(KnowledgeSyncService::class)->syncLibrary();
    expect($sync['created'])->toBe(0)->and($sync['updated'])->toBe(0)->and($sync['files'])->toBe([])
        ->and(KnowledgeSource::query()->count())->toBe(0);

    // 7–8. B importa con un Excel distinto (programas que B no tiene) y con uno vacío.
    foreach ([mkiExcel([mkiRow(1), mkiRow(2)]), mkiExcel([])] as $other) {
        $r = mkiImport($other, $botB);
        expect($r['created'])->toBe([])->and($r['updated'])->toBe([])->and($r['unchanged'])->toBe([])
            ->and($r['deactivated'])->toBe([])->and($r['assigned'])->toBe([])->and($r['already_assigned'])->toBe([])
            ->and($r['general']['status'])->toBe('not_found');
    }
    // Ni el borrado por código de B alcanza archivos de A.
    expect(app(KnowledgeSyncService::class)->deleteFilesForCode('MC-001'))->toBe(0)
        ->and(app(KnowledgeSyncService::class)->deleteFilesForCode('KB-MC-GENERAL-001'))->toBe(0)
        ->and(KnowledgeSource::query()->count())->toBe(0)
        ->and(DB::table('bot_knowledge_source')->where('bot_id', $botB->id)->count())->toBe(0);

    // 9. A: sus 58 fuentes, archivos, prioridades y asignaciones, idénticos.
    expect(mkiSnapshot($instA))->toEqual($before);
});

it('aislamiento: A y B con el MISMO código, distinto contenido y distinto archivo: ninguna operación de una toca lo de la otra', function () {
    [$instA, $celiaA, , $excelA] = mkiCtx(2);
    mkiImport($excelA, $celiaA);

    $generalB = str_replace('Una microcredencial es', 'En la institución B, una microcredencial es', (string) file_get_contents(__DIR__.'/../Fixtures/kb_mc_general.md'));
    $excelB = mkiExcel([mkiRow(1, ['Descripcion' => 'Descripción propia de la institución B.']), mkiRow(2)]);
    [$instB, $celiaB] = mkiCtx(excel: $excelB, fresh: false, generalFile: 'celia_kb_general_b.md', generalContent: $generalB);
    mkiImport($excelB, $celiaB);

    $fileA = 'biblioteca/microcredenciales/institucion-'.$instA->id.'/MC-001.md';
    $fileB = 'biblioteca/microcredenciales/institucion-'.$instB->id.'/MC-001.md';
    $sourceB = KnowledgeSource::query()->where('code', 'MC-001')->sole();
    expect(Storage::disk('knowledge')->exists($fileA))->toBeTrue()
        ->and(Storage::disk('knowledge')->exists($fileB))->toBeTrue()
        ->and((string) Storage::disk('knowledge')->get($fileB))->toContain('Descripción propia de la institución B.')
        ->and((string) Storage::disk('knowledge')->get($fileA))->not->toContain('institución B')
        ->and((string) $sourceB->content_es)->toContain('Descripción propia de la institución B.')
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('content_es'))->toContain('En la institución B')
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(6);

    // Decisiones manuales en B (estado y pausa del asesor) que A no puede deshacer.
    KnowledgeSource::query()->where('code', 'MC-002')->update(['status' => 'inactive']);
    app(KnowledgeAssignmentService::class)->toggle($celiaB, $sourceB, false);
    $b = mkiSnapshot($instB);

    // Operaciones en A: actualizar MC-001 por código, sincronizar y reimportar.
    app(CurrentInstitution::class)->set($instA->id);
    $rA = mkiImport(mkiExcel([mkiRow(1, ['Descripcion' => 'Descripción NUEVA de A.']), mkiRow(2)]), $celiaA);
    app(KnowledgeSyncService::class)->syncLibrary();
    expect($rA['updated'])->toBe(['MC-001'])
        ->and(KnowledgeSource::query()->where('code', 'MC-001')->value('content_es'))->toContain('Descripción NUEVA de A.')
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('content_es'))->not->toContain('institución B')
        ->and(mkiSnapshot($instB))->toEqual($b);
    $a = mkiSnapshot($instA);

    // Y al revés: operaciones en B no tocan A.
    app(CurrentInstitution::class)->set($instB->id);
    $rB = mkiImport(mkiExcel([mkiRow(1, ['Descripcion' => 'Otra descripción de B.']), mkiRow(2)]), $celiaB);
    app(KnowledgeSyncService::class)->syncLibrary();
    expect($rB['updated'])->toBe(['MC-001'])
        ->and($rB['kept_paused'])->toBe(['MC-001'])                               // la pausa de B se respeta
        ->and(KnowledgeSource::query()->where('code', 'MC-002')->value('status'))->toBe('inactive')
        ->and(mkiSnapshot($instA))->toEqual($a);
});

it('base general: se localiza por el source_file de la fuente de ESTA institución; si no coincide, está marcada por otra o la comparte otra institución, no se escribe', function (string $case) {
    [$instA, , , $excel] = mkiCtx(1);
    $path = 'biblioteca/microcredenciales/celia_kb_general_es_ver2.1.md';
    $raw = (string) Storage::disk('knowledge')->get($path);

    if ($case === 'source_file distinto') {
        Storage::disk('knowledge')->delete($path);
        Storage::disk('knowledge')->put('biblioteca/microcredenciales/otro_nombre.md', $raw);   // mismo código, otro archivo
    } elseif ($case === 'marcado por otra institución') {
        Storage::disk('knowledge')->put($path, (string) app(KnowledgeSyncService::class)->withInstitution($raw, $instA->id + 1000));
    } else {   // compartido: otra institución tiene una fuente con el mismo código y el mismo archivo
        mkiCtx(1, fresh: false);
        app(CurrentInstitution::class)->set($instA->id);
    }

    $report = mkiImport($excel);

    expect($report['general']['status'])->toBe(['source_file distinto' => 'not_found', 'marcado por otra institución' => 'not_found', 'compartido' => 'shared'][$case])
        ->and(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->value('priority'))->toBe(0)
        ->and(collect(Storage::disk('knowledge')->allFiles())->filter(fn ($f) => str_contains((string) Storage::disk('knowledge')->get($f), 'Prioridad: 6'))->count())->toBe(0);
})->with(['source_file distinto', 'marcado por otra institución', 'compartido']);

it('búsqueda clásica: en una pregunta general de certificación la base general va PRIMERO y cualquier ficha que entre se identifica por su programa', function () {
    $excel = base_path(MKI_REAL_EXCEL);
    [, $celia] = mkiCtx(excel: $excel);
    mkiImport($excel, $celia);

    $retrieved = app(KnowledgeRetriever::class)->retrieveWithSources($celia->id, '¿Qué certificación recibo al terminar?', 'es');
    $blocks = preg_split('/\n\n(?=## |<!--|# )/u', $retrieved['text']) ?: [];

    expect($retrieved['sources'][0])->toBe('KB-MC-GENERAL-001')
        ->and($retrieved['text'])->toStartWith('## Certificación');
    foreach (array_slice($retrieved['sources'], 1) as $code) {
        $name = (string) Program::query()->where('code', $code)->value('name_es');
        // Cada fragmento de una ficha individual lleva el nombre de SU programa en el título.
        expect($retrieved['text'])->toMatch('/## [^\n]*'.preg_quote($name, '/').'/u');
    }
});

// --- Búsqueda CLÁSICA: solo la ficha del programa identificado (pregunta o contexto) --------------
// Sin programa identificado (o con varios posibles) no entra NINGUNA ficha individual: una pregunta
// general no depende del modelo para ignorar una ficha irrelevante.

/** Celia (clásica) con las 57 fichas reales y la base general, todas asignadas. */
function mkiCeliaReal(): Bot
{
    $excel = base_path(MKI_REAL_EXCEL);
    [, $celia] = mkiCtx(excel: $excel);
    mkiImport($excel, $celia);

    return $celia;
}

/** @return list<string> códigos recuperados (con el historial del usuario, del más reciente al más antiguo) */
function mkiSources(Bot $bot, string $question, array $history = []): array
{
    return app(KnowledgeRetriever::class)->retrieveWithSources($bot->id, $question, 'es', null, $history)['sources'];
}

/** @return list<string> solo las fichas de programa entre los códigos */
function mkiCards(array $codes): array
{
    $cards = KnowledgeSource::query()->where('type', 'programa_academico')->pluck('code')->all();

    return array_values(array_intersect($codes, $cards));
}

it('1–2 · preguntas generales: solo bases generales, ninguna ficha individual (ni MC-004 en certificación)', function (string $question) {
    $celia = mkiCeliaReal();
    $sources = mkiSources($celia, $question);

    expect($sources)->toContain('KB-MC-GENERAL-001')
        ->and(mkiCards($sources))->toBe([])
        ->and($sources)->not->toContain('MC-004');
})->with([
    'duración general' => '¿Cuánto duran las microcredenciales?',
    'certificación' => '¿Qué certificación recibo al terminar?',
    'formas de pago' => '¿Cuáles son las formas de pago?',
    'inscripción' => '¿Cómo es el proceso de inscripción?',
    'metodología' => '¿Cómo es la metodología de estudio?',
    'requisitos generales' => '¿Qué requisitos necesito para matricularme?',
]);

it('3–5 · un programa identificado por código, nombre completo o parte distintiva: SOLO su ficha', function (string $question, string $code) {
    $celia = mkiCeliaReal();

    expect(mkiCards(mkiSources($celia, $question)))->toBe([$code]);
})->with([
    'código' => ['¿Cuánto dura el MC-001?', 'MC-001'],
    'código en minúsculas' => ['Información de mc-052', 'MC-052'],
    'nombre completo' => ['¿Qué aprenderé en Dirección y Toma de Decisiones Organizacionales?', 'MC-001'],
    'nombre completo (otro)' => ['¿Cuál es la modalidad de Marketing Digital estratégico?', 'MC-052'],
    'parte distintiva' => ['Información de toma de decisiones organizacionales', 'MC-001'],
    'parte distintiva (otra)' => ['¿Qué veré en coaching sistémico?', 'MC-036'],
]);

it('6–7 · referencias genéricas o a varios programas: no se elige ninguno (ninguna ficha individual)', function (string $question) {
    $celia = mkiCeliaReal();
    $sources = mkiSources($celia, $question);

    expect(mkiCards($sources))->toBe([]);
})->with([
    'dirección' => ['¿Qué aprendo en dirección?'],
    'liderazgo' => ['Quiero información sobre liderazgo'],
    'estrategia' => ['¿Cuánto dura estrategia?'],
    'marketing' => ['Me interesa marketing'],
    'talento humano' => ['¿Qué tienen de talento humano?'],
    'dos programas' => ['Compara Dirección y Toma de Decisiones Organizacionales con Marketing Digital estratégico'],
    'dos códigos' => ['¿Qué diferencia hay entre MC-001 y MC-052?'],
]);

it('8 · seguimiento breve tras identificar un programa: sigue SOLO con esa ficha', function (string $followUp) {
    $celia = mkiCeliaReal();
    $history = ['Háblame de Dirección y Toma de Decisiones Organizacionales'];

    expect(mkiCards(mkiSources($celia, $history[0])))->toBe(['MC-001'])
        ->and(mkiCards(mkiSources($celia, $followUp, $history)))->toBe(['MC-001']);
})->with([
    'duración' => '¿Cuánto dura?',
    'modalidad' => '¿Y la modalidad?',
    'enlace' => '¿Dónde lo veo?',
]);

it('8 · el contexto se conserva a través de varias continuaciones breves', function () {
    $celia = mkiCeliaReal();

    expect(mkiCards(mkiSources($celia, '¿Qué credencial otorga?', ['¿Y la modalidad?', '¿Cuánto dura?', 'Háblame de MC-001'])))->toBe(['MC-001']);
});

it('9 · de un programa a una pregunta general: solo la base general', function () {
    $celia = mkiCeliaReal();
    $sources = mkiSources($celia, '¿Qué certificación reciben las microcredenciales en general?', ['Háblame de Dirección y Toma de Decisiones Organizacionales']);

    expect($sources)->toContain('KB-MC-GENERAL-001')->and(mkiCards($sources))->toBe([]);
});

it('9 · un mensaje ambiguo o un cambio de tema largo cierran el contexto', function () {
    $celia = mkiCeliaReal();

    expect(mkiCards(mkiSources($celia, '¿Cuánto dura?', ['Me interesa marketing', 'Háblame de MC-001'])))->toBe([])
        ->and(mkiCards(mkiSources($celia, '¿Cuánto dura?', ['Quiero saber cómo funcionan los pagos con tarjeta y las becas para empresas grandes', 'Háblame de MC-001'])))->toBe([]);
});

it('10 · de un programa a otro: solo la ficha del nuevo', function () {
    $celia = mkiCeliaReal();

    expect(mkiCards(mkiSources($celia, '¿Y Marketing Digital estratégico cuánto dura?', ['Háblame de Dirección y Toma de Decisiones Organizacionales'])))->toBe(['MC-052'])
        ->and(mkiCards(mkiSources($celia, '¿Y el MC-036?', ['Háblame de MC-001'])))->toBe(['MC-036']);
});

it('8 (extremo a extremo) · en «Probar asesor», Celia recupera MC-001 y lo mantiene en «¿Cuánto dura?»; la IA no recibe otras fichas', function () {
    $celia = mkiCeliaReal();
    $integration = \Modules\Integrations\Models\Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    \Modules\Integrations\Models\AiProcessConfig::factory()->create(['bot_id' => $celia->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $fake = new \Modules\Ai\Tests\Support\FakeAiChatClient('{"reply": "Respuesta del modelo.", "action": "answer"}');
    app()->instance(\Modules\Ai\Services\AiChatClient::class, $fake);
    $chat = Livewire::test(\Modules\Ai\Livewire\Advisor\Preview::class, ['token' => app(\Modules\Ai\Services\AdvisorPreviewLinkService::class)->generate($celia)])->call('start');
    $last = fn () => \Modules\Crm\Models\Message::query()->where('sender_type', 'celia')->orderByDesc('id')->first();

    $chat->set('draft', 'Háblame de Dirección y Toma de Decisiones Organizacionales')->call('send');
    $first = $last()->meta['knowledge_sources'];
    $chat->set('draft', '¿Cuánto dura?')->call('send');
    $second = $last()->meta['knowledge_sources'];
    $prompt = (string) json_encode(end($fake->calls), JSON_UNESCAPED_UNICODE);
    $chat->set('draft', '¿Qué certificación reciben las microcredenciales en general?')->call('send');
    $third = $last()->meta['knowledge_sources'];

    expect(mkiCards($first))->toBe(['MC-001'])
        ->and(mkiCards($second))->toBe(['MC-001'])
        ->and($prompt)->toContain('Dirección y Toma de Decisiones Organizacionales')->not->toContain('Estrategia y Gestión Comercial')
        ->and(mkiCards($third))->toBe([])
        ->and($third)->toContain('KB-MC-GENERAL-001');
});

it('11 · aislamiento: el programa se identifica solo entre las fichas asignadas al asesor de la institución activa', function () {
    [$instA, $celiaA, , $excelA] = mkiCtx(2);
    mkiImport($excelA, $celiaA);
    $excelB = mkiExcel([mkiRow(1, ['Nombre del programa' => 'Programa Exclusivo de la Institución B', 'Descripcion' => 'Descripción del programa exclusivo de B.'])]);
    [, $celiaB] = mkiCtx(excel: $excelB, fresh: false, generalFile: 'celia_kb_general_b.md');
    mkiImport($excelB, $celiaB);

    // En B, «MC-001» es SU ficha (otro nombre); el nombre del MC-001 de A no identifica nada en B.
    $b = app(KnowledgeRetriever::class)->retrieveWithSources($celiaB->id, '¿Qué aprendo en MC-001?', 'es');
    expect(mkiCards($b['sources']))->toBe(['MC-001'])
        ->and($b['text'])->toContain('Programa Exclusivo de la Institución B')->not->toContain('Dirección y Toma de Decisiones')
        ->and(mkiCards(mkiSources($celiaB, '¿Qué aprendo en Dirección y Toma de Decisiones Organizacionales?')))->toBe([]);

    // Y en A, al revés.
    app(CurrentInstitution::class)->set($instA->id);
    expect(app(KnowledgeRetriever::class)->retrieveWithSources($celiaA->id, '¿Qué aprendo en MC-001?', 'es')['text'])->not->toContain('Institución B');
});

it('12 · la búsqueda PRECISA no cambia: no aplica el filtro clásico y su detección de programas es la de siempre', function () {
    $celia = mkiCeliaReal();
    $celia->update(['knowledge_retrieval' => Bot::RETRIEVAL_PRECISE]);
    $sources = KnowledgeSource::query()->get()->map(fn (KnowledgeSource $s): array => ['code' => (string) $s->code, 'type' => $s->type, 'category' => $s->category, 'content' => (string) $s->content_es])->all();
    $mentions = app(\Modules\Ai\Services\ProgramMentions::class);

    // Una palabra genérica de varias fichas: la precisa las sigue considerando (bonus a las empatadas),
    // mientras la clásica no admite ninguna.
    $terms = \Modules\Ai\Support\KnowledgeText::queryTerms('¿Qué aprendo en dirección?');
    expect(count($mentions->named($sources, $terms, null)))->toBeGreaterThan(1)
        ->and($mentions->scope($sources, '¿Qué aprendo en dirección?')['state'])->toBe('ambiguous');

    // En modo preciso, una pregunta general puede seguir recuperando secciones de fichas (su ranking).
    $retrieved = app(KnowledgeRetriever::class)->retrieveWithSources($celia->id, '¿Qué aprendo en dirección?', 'es');
    expect($retrieved)->toHaveKey('diagnostics')
        ->and(mkiCards($retrieved['sources']))->not->toBe([]);
});
