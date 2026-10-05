<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeIngestService;
use Modules\Audit\Models\AuditLog;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Services\ProgramProvisioningService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Biblioteca → «Programa Académico»: alta manual de programas en el CATÁLOGO institucional
 * (misma tabla programs que alimenta el selector), individual («+ Añadir programa») y masiva
 * («Importar»), sin catálogo paralelo y sin tocar la ingesta de fichas.
 *
 * @return array{0: Institution, 1: User}
 */
function pcCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    Storage::fake('knowledge');

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

function pcFile(string $code): UploadedFile
{
    return UploadedFile::fake()->createWithContent($code.'.md', "# Ficha {$code}\n<!-- Codigo: {$code} · Idioma: es · Prioridad: 3 -->\n\n## Resumen\nContenido {$code}.");
}

/** Crea el Micro MBA desde el modal, como lo haría un administrador. */
function pcCreateMicroMba(User $admin): Testable
{
    return Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')
        ->set('newProgramName', 'Micro MBA')
        ->set('newProgramCode', 'MMBA-001')
        ->set('newProgramLine', 'micro_mba')
        ->call('createProgram');
}

// 1, 6 · Alta individual -------------------------------------------------------------

it('un administrador crea un programa desde el modal: queda activo, elegido en el selector y auditado', function () {
    [$inst, $admin] = pcCtx();

    $component = pcCreateMicroMba($admin)->assertHasNoErrors()->assertSet('showAddProgram', false);

    $program = Program::query()->where('code', 'MMBA-001')->firstOrFail();
    expect($program->name_es)->toBe('Micro MBA')
        ->and($program->line)->toBe('micro_mba')
        ->and($program->status)->toBe('active')
        ->and($program->category_id)->toBeNull()
        ->and($program->url)->toBe('');

    // El selector se refresca solo y deja el nuevo programa elegido, con su línea.
    $component->assertSet('programId', (string) $program->id)
        ->assertSet('programLine', 'micro_mba')
        ->assertSee('MMBA-001 · Micro MBA');
    expect($component->viewData('programs')->pluck('id')->all())->toContain($program->id);

    $log = AuditLog::query()->where('action', 'program.created')->firstOrFail();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->institution_id)->toBe($inst->id)
        ->and($log->auditable_id)->toBe($program->id)
        ->and($log->changes)->toMatchArray(['code' => 'MMBA-001', 'line' => 'micro_mba', 'method' => 'manual']);
});

// 2 · Permisos (el servidor lo comprueba, no solo el botón) ------------------------------

it('un usuario sin permiso de catálogo no ve los botones ni puede crear ni importar', function () {
    [, $admin] = pcCtx();
    Gate::before(fn ($user, string $ability, array $args = []) => $ability === 'create' && ($args[0] ?? null) === Program::class ? false : null);

    Livewire::actingAs($admin)->test(Library::class)
        ->assertDontSee('Añadir programa')
        ->call('openAddProgram')->assertForbidden();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('newProgramName', 'Micro MBA')->set('newProgramCode', 'MMBA-001')->set('newProgramLine', 'micro_mba')
        ->call('createProgram')->assertForbidden();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('importText', 'Micro MBA | MMBA-001 | micro_mba')
        ->call('confirmImport')->assertForbidden();

    expect(Program::query()->count())->toBe(0);
});

it('un rol sin acceso a la Biblioteca (Marketing) no llega a la pantalla', function () {
    [$inst] = pcCtx();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    Livewire::actingAs($marketing)->test(Library::class)->assertForbidden();
});

// 3 · Institución ----------------------------------------------------------------------

it('el programa creado conserva la institución activa y no se ve desde otra', function () {
    [$inst, $admin] = pcCtx();
    pcCreateMicroMba($admin)->assertHasNoErrors();

    $program = Program::query()->where('code', 'MMBA-001')->firstOrFail();
    expect($program->institution_id)->toBe($inst->id);

    $other = Institution::factory()->create();
    $seen = app(CurrentInstitution::class)->runFor($other->id, fn () => Program::query()->where('code', 'MMBA-001')->exists());
    expect($seen)->toBeFalse();
});

// 4, 5 · Duplicados ----------------------------------------------------------------------

it('no permite un código duplicado (sin distinguir mayúsculas) ni crea un segundo registro', function () {
    [, $admin] = pcCtx();
    Program::factory()->create(['code' => 'MMBA-001', 'name_es' => 'Otro nombre', 'line' => 'micro_mba']);

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')
        ->set('newProgramName', 'Micro MBA')->set('newProgramCode', 'mmba-001')->set('newProgramLine', 'micro_mba')
        ->call('createProgram')
        ->assertHasErrors('newProgramCode')
        ->assertSee('Ya existe un programa con este nombre o código')
        ->assertSet('showAddProgram', true);

    expect(Program::query()->count())->toBe(1);
});

it('no crea un duplicado por nombre normalizado (mayúsculas, tildes y espacios)', function () {
    [, $admin] = pcCtx();
    Program::factory()->create(['code' => 'MC-900', 'name_es' => 'Gestión de Proyectos', 'line' => 'microcredenciales']);

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')
        ->set('newProgramName', '  gestion   DE proyectos ')->set('newProgramCode', 'PE-900')->set('newProgramLine', 'programas_ejecutivos')
        ->call('createProgram')
        ->assertHasErrors('newProgramCode');

    expect(Program::query()->count())->toBe(1);
});

it('si coincide con un programa INACTIVO ofrece activarlo en lugar de duplicarlo', function () {
    [, $admin] = pcCtx();
    $old = Program::factory()->create(['code' => 'MMBA-001', 'name_es' => 'Micro MBA', 'line' => 'micro_mba', 'status' => 'inactive']);

    pcCreateMicroMba($admin)
        ->assertHasErrors('newProgramCode')
        ->assertSet('inactiveMatchId', $old->id)
        ->assertSee('Activar el existente')
        ->call('activateMatchedProgram')
        ->assertSet('programId', (string) $old->id)
        ->assertSet('showAddProgram', false);

    expect($old->fresh()->status)->toBe('active')->and(Program::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'program.activated')->exists())->toBeTrue();
});

it('el mismo código puede existir en otra institución (la unicidad es por institución)', function () {
    [$inst, $admin] = pcCtx();
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => Program::factory()->create(['code' => 'MMBA-001', 'name_es' => 'Micro MBA', 'line' => 'micro_mba']));
    app(CurrentInstitution::class)->set($inst->id);

    pcCreateMicroMba($admin)->assertHasNoErrors();
    expect(Program::query()->where('code', 'MMBA-001')->count())->toBe(1);
});

// 6, 7 · Selector y ficha ----------------------------------------------------------------

it('el programa recién creado se puede elegir y asociar a una ficha académica', function () {
    [, $admin] = pcCtx();

    pcCreateMicroMba($admin)
        ->assertHasNoErrors()
        ->set('programDocs', [pcFile('MMBA-FICHA')])
        ->call('uploadProgramDocs')
        ->assertHasNoErrors();

    $program = Program::query()->where('code', 'MMBA-001')->firstOrFail();
    $src = KnowledgeSource::query()->where('code', 'MMBA-FICHA')->firstOrFail();
    expect($src->type)->toBe('programa_academico')
        ->and($src->category)->toBe('micro_mba')
        ->and($src->program_id)->toBe($program->id);
});

// 10 · Líneas y área -----------------------------------------------------------------------

it('rechaza líneas que no son de programa (inexistente o la institucional)', function () {
    [, $admin] = pcCtx();

    foreach (['cursos_libres', 'general_institucional', ''] as $line) {
        Livewire::actingAs($admin)->test(Library::class)
            ->call('openAddProgram')
            ->set('newProgramName', 'Programa X')->set('newProgramCode', 'PX-001')->set('newProgramLine', $line)
            ->call('createProgram')
            ->assertHasErrors('newProgramLine');
    }

    expect(Program::query()->count())->toBe(0);
});

it('en Microcredenciales el área es obligatoria y debe existir; en otras líneas no se pide', function () {
    [, $admin] = pcCtx();
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')
        ->set('newProgramName', 'Micro Liderazgo')->set('newProgramCode', 'MC-777')->set('newProgramLine', 'microcredenciales')
        ->assertSeeHtml('wire:model="newProgramArea"')
        ->call('createProgram')->assertHasErrors('newProgramArea')
        ->set('newProgramArea', (string) $area->id)->call('createProgram')->assertHasNoErrors();

    expect(Program::query()->where('code', 'MC-777')->value('category_id'))->toBe($area->id);

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')->set('newProgramLine', 'diplomas_avanzados')
        ->assertDontSeeHtml('wire:model="newProgramArea"');
});

// 8, 9, 10 · Alta masiva ---------------------------------------------------------------------

it('el alta masiva revisa antes de guardar y crea varios programas', function () {
    [$inst, $admin] = pcCtx();
    ProgramCategory::factory()->create(['name_es' => 'Recursos Humanos']);
    $text = "Nombre | Código | Línea | Área\n"
        ."Micro MBA | MMBA-001 | micro_mba\n"
        ."Diploma Avanzado en Dirección Estratégica\tDA-020\tDiplomas Avanzados\n"
        ."Professional Certificate en Liderazgo ; PE-030 ; Programa Ejecutivo\n"
        .'Micro Talento | MC-300 | microcredenciales | recursos humanos';

    $component = Livewire::actingAs($admin)->test(Library::class)
        ->call('openImportPrograms')
        ->set('importText', $text)
        ->call('previewImport')
        ->assertSee('4 se crearán');
    expect(Program::query()->count())->toBe(0); // revisar no escribe

    $component->call('confirmImport')->assertSet('importResult', ['created' => 4, 'duplicate' => 0, 'error' => 0]);

    expect(Program::query()->orderBy('code')->pluck('line', 'code')->all())->toBe([
        'DA-020' => 'diplomas_avanzados',
        'MC-300' => 'microcredenciales',
        'MMBA-001' => 'micro_mba',
        'PE-030' => 'programas_ejecutivos',
    ])->and(Program::query()->pluck('institution_id')->unique()->all())->toBe([$inst->id])
        ->and(AuditLog::query()->where('action', 'program.created')->get()->pluck('changes.method')->unique()->all())->toBe(['importacion_masiva']);
});

it('el alta masiva omite duplicados y errores sin abortar el resto', function () {
    [, $admin] = pcCtx();
    Program::factory()->create(['code' => 'DA-020', 'name_es' => 'Diploma existente', 'line' => 'diplomas_avanzados']);
    $text = implode("\n", [
        'Micro MBA | MMBA-001 | micro_mba',          // se crea
        'Otro diploma | DA-020 | diplomas_avanzados', // código existente
        'Micro MBA bis | MMBA-001 | micro_mba',      // repetido en la lista
        'Curso libre | CL-1 | cursos_libres',         // línea inválida
        'Sin código | | micro_mba',                  // código vacío
        'Código raro | MBA 01! | micro_mba',         // código inválido
        'Micro sin área | MC-301 | microcredenciales', // falta el área
        'Doctorado X | DOC-001 | Doctorados',        // se crea
    ]);

    $preview = app(ProgramProvisioningService::class)->previewBulk($text);
    expect($preview['summary'])->toBe(['create' => 2, 'duplicate' => 2, 'error' => 4]);

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openImportPrograms')->set('importText', $text)->call('previewImport')
        ->call('confirmImport')
        ->assertSet('importResult', ['created' => 2, 'duplicate' => 2, 'error' => 4])
        ->assertSee('Código no válido')->assertSee('Línea no válida');

    expect(Program::query()->orderBy('code')->pluck('code')->all())->toBe(['DA-020', 'DOC-001', 'MMBA-001']);
});

// 11, 12 · Conocimiento existente y regla de la Base de Conocimiento -------------------------

it('crear programas no toca las fuentes de conocimiento ni sus asignaciones', function () {
    [, $admin] = pcCtx();
    $linked = Program::factory()->create(['code' => 'MC-001', 'line' => 'microcredenciales']);
    KnowledgeSource::factory()->create(['code' => 'KB-1', 'bot_id' => null, 'category' => 'general_institucional', 'type' => 'base_conocimiento', 'program_id' => null]);
    $ficha = KnowledgeSource::factory()->create(['code' => 'MC-001-FICHA', 'bot_id' => null, 'category' => 'microcredenciales', 'type' => 'programa_academico', 'program_id' => $linked->id]);
    Bot::factory()->create(['status' => 'active'])->knowledgeSources()->attach($ficha->id, ['is_active' => true]);
    $snapshot = fn () => [
        DB::table('knowledge_sources')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('bot_knowledge_source')->orderBy('knowledge_source_id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();

    pcCreateMicroMba($admin)->assertHasNoErrors();
    app(ProgramProvisioningService::class)->importBulk('Doctorado X | DOC-001 | doctorados');

    expect($snapshot())->toBe($before);
});

it('la Base de Conocimiento general sigue rechazando program_id, también con un programa recién creado', function () {
    [, $admin] = pcCtx();
    pcCreateMicroMba($admin)->assertHasNoErrors();
    $program = Program::query()->where('code', 'MMBA-001')->firstOrFail();

    expect(fn () => app(KnowledgeIngestService::class)->ingest([pcFile('KB-GEN')], [
        'type' => 'base_conocimiento', 'line' => 'general_institucional', 'program_id' => $program->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(KnowledgeSource::query()->count())->toBe(0);
});

// Selector de «Programa Académico» filtrado por la línea elegida -------------------------------

it('el selector de programas solo muestra los programas activos de la línea elegida, y el buscador busca dentro de ella', function () {
    [, $admin] = pcCtx();
    $mmba = Program::factory()->create(['code' => 'MMBA-001', 'name_es' => 'Micro MBA', 'line' => 'micro_mba']);
    $mmba2 = Program::factory()->create(['code' => 'MMBA-002', 'name_es' => 'Micro MBA Finanzas', 'line' => 'micro_mba']);
    Program::factory()->create(['code' => 'MMBA-003', 'name_es' => 'Micro MBA inactivo', 'line' => 'micro_mba', 'status' => 'inactive']);
    Program::factory()->create(['code' => 'MMBA-004', 'name_es' => 'Micro MBA borrado', 'line' => 'micro_mba'])->delete();
    Program::factory()->create(['code' => 'MC-001', 'name_es' => 'Micro de Liderazgo', 'line' => 'microcredenciales']);
    Program::factory()->create(['code' => 'DA-001', 'name_es' => 'Diploma MBA', 'line' => 'diplomas_avanzados']);

    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => Program::factory()->create(['code' => 'MMBA-900', 'name_es' => 'Micro MBA ajeno', 'line' => 'micro_mba']));

    $component = Livewire::actingAs($admin)->test(Library::class);
    expect($component->viewData('programs'))->toHaveCount(0); // sin línea, no se ofrece ninguno
    $component->assertSee('Elige primero la línea…');

    $component->set('programLine', 'micro_mba');
    expect($component->viewData('programs')->pluck('id')->sort()->values()->all())->toBe([$mmba->id, $mmba2->id]);
    $component->assertSee('MMBA-001 · Micro MBA')->assertDontSee('MC-001 · Micro de Liderazgo')->assertDontSee('DA-001 · Diploma MBA');

    // El buscador busca SOLO dentro de la línea («MBA» también está en el Diploma de otra línea).
    $component->set('programSearch', 'Finanzas');
    expect($component->viewData('programs')->pluck('id')->all())->toBe([$mmba2->id]);
    $component->set('programSearch', 'MBA');
    expect($component->viewData('programs')->pluck('code')->sort()->values()->all())->toBe(['MMBA-001', 'MMBA-002']);
});

it('cambiar de línea suelta el programa elegido si no pertenece a la nueva línea', function () {
    [, $admin] = pcCtx();
    $mmba = Program::factory()->create(['code' => 'MMBA-001', 'name_es' => 'Micro MBA', 'line' => 'micro_mba']);
    $mc = Program::factory()->create(['code' => 'MC-001', 'name_es' => 'Micro de Liderazgo', 'line' => 'microcredenciales']);

    $component = Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'micro_mba')->set('programId', (string) $mmba->id)
        ->set('programLine', 'microcredenciales')
        ->assertSet('programId', '');                   // incompatible: se limpia
    expect($component->viewData('programs')->pluck('id')->all())->toBe([$mc->id]);

    $component->set('programId', (string) $mc->id)
        ->set('programLine', 'microcredenciales')
        ->assertSet('programId', (string) $mc->id);     // compatible: se conserva
});

// Estancias ------------------------------------------------------------------------------

it('Estancias aparece en Añadir programa e Importar, y se puede crear e importar un programa de Estancias', function () {
    [, $admin] = pcCtx();

    $component = Livewire::actingAs($admin)->test(Library::class)->call('openAddProgram');
    expect($component->viewData('programLines'))->toHaveKey('estancias');
    $component->assertSee('Estancias')
        ->set('newProgramName', 'Estancia Internacional en Madrid')->set('newProgramCode', 'EST-001')->set('newProgramLine', 'estancias')
        ->call('createProgram')->assertHasNoErrors()
        ->assertSet('programLine', 'estancias');

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openImportPrograms')->assertSee('estancias')
        ->set('importText', "Estancia en Boston | EST-002 | Estancias\nEstancia en Roma | EST-003 | estancias")
        ->call('previewImport')->call('confirmImport')
        ->assertSet('importResult', ['created' => 2, 'duplicate' => 0, 'error' => 0]);

    expect(Program::query()->orderBy('code')->pluck('line', 'code')->all())
        ->toBe(['EST-001' => 'estancias', 'EST-002' => 'estancias', 'EST-003' => 'estancias']);
});

// URL opcional --------------------------------------------------------------------------------

it('un programa sin URL se crea, aparece en el selector y recibe su ficha al momento', function () {
    [, $admin] = pcCtx();

    Livewire::actingAs($admin)->test(Library::class)
        ->call('openAddProgram')
        ->set('newProgramName', 'Doctorado en Educación')->set('newProgramCode', 'DOC-010')->set('newProgramLine', 'doctorados')
        ->set('newProgramUrl', '')
        ->call('createProgram')->assertHasNoErrors()
        ->assertSee('DOC-010 · Doctorado en Educación')
        ->set('programDocs', [pcFile('DOC-010-FICHA')])->call('uploadProgramDocs')->assertHasNoErrors();

    $program = Program::query()->where('code', 'DOC-010')->firstOrFail();
    expect($program->url)->toBe('')
        ->and(KnowledgeSource::query()->where('code', 'DOC-010-FICHA')->value('program_id'))->toBe($program->id);
});
