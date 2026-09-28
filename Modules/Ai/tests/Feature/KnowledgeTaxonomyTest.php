<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeIngestService;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Centro de Conocimiento — Bloque 4a: taxonomía fija de línea (category) y tipo (type),
 * vínculo con el catálogo (program_id) y los dos espacios de subida de la Biblioteca.
 *
 * @return array{0: Institution, 1: User}
 */
function txnCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    Storage::fake('knowledge');

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

function txnMd(string $code, string $category = ''): string
{
    $cat = $category !== '' ? " · Categoria: {$category}" : '';

    return "# Fuente {$code}\n<!-- Codigo: {$code} · Idioma: es{$cat} · Prioridad: 3 -->\n\n## Resumen\nContenido {$code}.";
}

function txnFile(string $code, string $category = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent($code.'.md', txnMd($code, $category));
}

// --- Programa Académico ------------------------------------------------------

it('Programa Académico guarda type, línea y program_id, y coloca el archivo en biblioteca/{linea}/', function () {
    [, $admin] = txnCtx();
    $program = Program::factory()->create(['status' => 'active']);

    Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'microcredenciales')
        ->set('programId', (string) $program->id)
        ->set('programDocs', [txnFile('MC-PA-1')])
        ->call('uploadProgramDocs')
        ->assertHasNoErrors()
        ->assertSee('Nuevo');

    $src = KnowledgeSource::query()->where('code', 'MC-PA-1')->firstOrFail();
    expect($src->type)->toBe('programa_academico')
        ->and($src->category)->toBe('microcredenciales')
        ->and($src->program_id)->toBe($program->id);
    expect(Storage::disk('knowledge')->exists('biblioteca/microcredenciales/MC-PA-1.md'))->toBeTrue();
});

it('Programa Académico exige un programa del catálogo', function () {
    [, $admin] = txnCtx();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'microcredenciales')
        ->set('programDocs', [txnFile('MC-PA-2')])
        ->call('uploadProgramDocs')
        ->assertHasErrors(['programId' => 'required']);

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('Programa Académico rechaza programas inactivos o borrados', function () {
    [, $admin] = txnCtx();
    $inactive = Program::factory()->create(['status' => 'inactive']);
    $deleted = Program::factory()->create(['status' => 'active']);
    $deleted->delete();

    foreach ([$inactive, $deleted] as $program) {
        Livewire::actingAs($admin)->test(Library::class)
            ->set('programLine', 'microcredenciales')
            ->set('programId', (string) $program->id)
            ->set('programDocs', [txnFile('MC-PA-3')])
            ->call('uploadProgramDocs')
            ->assertHasErrors(['programId']);
    }

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('Programa Académico no admite la línea General institucional', function () {
    [, $admin] = txnCtx();
    $program = Program::factory()->create(['status' => 'active']);

    Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'general_institucional')
        ->set('programId', (string) $program->id)
        ->set('programDocs', [txnFile('MC-PA-4')])
        ->call('uploadProgramDocs')
        ->assertHasErrors(['programLine' => 'in']);

    expect(KnowledgeSource::query()->count())->toBe(0);
});

// --- Base de Conocimiento ----------------------------------------------------

it('Base de Conocimiento guarda type y línea (incluida la institucional) sin programa', function () {
    [, $admin] = txnCtx();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('kbLine', 'general_institucional')
        ->set('docs', [txnFile('KB-INST-1')])
        ->call('uploadDocs')
        ->assertHasNoErrors();

    $src = KnowledgeSource::query()->where('code', 'KB-INST-1')->firstOrFail();
    expect($src->type)->toBe('base_conocimiento')
        ->and($src->category)->toBe('general_institucional')
        ->and($src->program_id)->toBeNull();
});

it('Base de Conocimiento NO admite program_id (el servicio lo rechaza)', function () {
    txnCtx();
    $program = Program::factory()->create(['status' => 'active']);

    expect(fn () => app(KnowledgeIngestService::class)->ingest([txnFile('KB-X')], [
        'type' => 'base_conocimiento', 'line' => 'microcredenciales', 'program_id' => $program->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('re-subir como Base de Conocimiento una fuente de programa la desvincula del programa', function () {
    [, $admin] = txnCtx();
    $program = Program::factory()->create(['status' => 'active']);

    Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'microcredenciales')->set('programId', (string) $program->id)
        ->set('programDocs', [txnFile('MC-MOVE')])->call('uploadProgramDocs');
    Livewire::actingAs($admin)->test(Library::class)
        ->set('kbLine', 'microcredenciales')->set('docs', [txnFile('MC-MOVE')])->call('uploadDocs')
        ->assertSee('Actualizado');

    $src = KnowledgeSource::query()->where('code', 'MC-MOVE')->firstOrFail();
    expect($src->type)->toBe('base_conocimiento')->and($src->program_id)->toBeNull();
});

// --- Lista fija de líneas ----------------------------------------------------

it('una línea fuera de la lista fija se rechaza (UI y servicio)', function () {
    [, $admin] = txnCtx();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('kbLine', 'estancias')
        ->set('docs', [txnFile('KB-EST')])
        ->call('uploadDocs')
        ->assertHasErrors(['kbLine' => 'in']);

    expect(fn () => app(KnowledgeIngestService::class)->ingest([txnFile('KB-EST')], [
        'type' => 'base_conocimiento', 'line' => 'estancias',
    ]))->toThrow(InvalidArgumentException::class);

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('rechaza el archivo cuya Categoria declara OTRA línea válida', function () {
    [, $admin] = txnCtx();

    Livewire::actingAs($admin)->test(Library::class)
        ->set('kbLine', 'maestrias')
        ->set('docs', [txnFile('KB-MIS', 'Doctorados'), txnFile('KB-OK', 'Maestrías')])
        ->call('uploadDocs')
        ->assertSee('Rechazado')
        ->assertSee('no coincide con la línea elegida');

    expect(KnowledgeSource::query()->where('code', 'KB-MIS')->exists())->toBeFalse();
    expect(KnowledgeSource::query()->where('code', 'KB-OK')->value('category'))->toBe('maestrias');
});

// --- syncLibrary: no pisa tipo ni línea -------------------------------------

it('syncLibrary no sobrescribe el tipo al actualizar y conserva la línea si la del archivo no es válida', function () {
    txnCtx();
    $program = Program::factory()->create(['status' => 'active']);
    app(KnowledgeIngestService::class)->ingest([txnFile('MC-SYNC')], [
        'type' => 'programa_academico', 'line' => 'microcredenciales', 'program_id' => $program->id,
    ]);

    // El archivo pasa a declarar una Categoria en texto libre y se mueve a una carpeta no válida.
    Storage::disk('knowledge')->delete('biblioteca/microcredenciales/MC-SYNC.md');
    Storage::disk('knowledge')->put('biblioteca/estancias/MC-SYNC.md', txnMd('MC-SYNC', 'Estancias cortas'));
    // Fuente nueva con línea inválida: queda sin línea (no entra texto libre).
    Storage::disk('knowledge')->put('biblioteca/estancias/NEW-FREE.md', txnMd('NEW-FREE', 'Estancias cortas'));

    app(KnowledgeSyncService::class)->syncLibrary();

    $src = KnowledgeSource::query()->where('code', 'MC-SYNC')->firstOrFail();
    expect($src->type)->toBe('programa_academico')
        ->and($src->category)->toBe('microcredenciales')
        ->and($src->program_id)->toBe($program->id);

    $new = KnowledgeSource::query()->where('code', 'NEW-FREE')->firstOrFail();
    expect($new->type)->toBe('base_conocimiento')->and($new->category)->toBeNull();
});

it('las filas antiguas fuera de la taxonomía se conservan tal cual al sincronizar', function () {
    txnCtx();
    KnowledgeSource::factory()->create(['code' => 'OLD-1', 'bot_id' => null, 'type' => 'general', 'category' => null]);
    Storage::disk('knowledge')->put('biblioteca/OLD-1.md', txnMd('OLD-1'));

    app(KnowledgeSyncService::class)->syncLibrary();

    $old = KnowledgeSource::query()->where('code', 'OLD-1')->firstOrFail();
    expect($old->type)->toBe('general')->and($old->category)->toBeNull();
});

// --- Listado: filtros por línea y tipo ---------------------------------------

it('la Biblioteca filtra por tipo y por línea', function () {
    [, $admin] = txnCtx();
    KnowledgeSource::factory()->create(['code' => 'F-PA', 'name' => 'Fuente programa', 'bot_id' => null, 'type' => 'programa_academico', 'category' => 'micro_mba']);
    KnowledgeSource::factory()->create(['code' => 'F-KB', 'name' => 'Fuente base', 'bot_id' => null, 'type' => 'base_conocimiento', 'category' => 'doctorados']);
    KnowledgeSource::factory()->create(['code' => 'F-OLD', 'name' => 'Fuente antigua', 'bot_id' => null, 'type' => 'general', 'category' => null]);

    Livewire::actingAs($admin)->test(Library::class)
        ->set('filterType', 'programa_academico')
        ->assertSee('Fuente programa')->assertDontSee('Fuente base')->assertDontSee('Fuente antigua')
        ->set('filterType', 'sin_tipo')
        ->assertSee('Fuente antigua')->assertDontSee('Fuente programa')
        ->set('filterType', '')
        ->set('filterCategory', 'doctorados')
        ->assertSee('Fuente base')->assertDontSee('Fuente programa');
});
