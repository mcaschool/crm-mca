<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Centro de Conocimiento — pestaña Biblioteca (Bloque 2): subida validada, upsert sin
 * duplicar, destino por categoría, toggle de estado, borrado con cascada + anti-resurrección,
 * y gating solo-Admin.
 */
function libInstitution(): Institution
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return $inst;
}

function libAdmin(int $instId): User
{
    return User::factory()->create(['institution_id' => $instId, 'role' => 'admin']);
}

function md(string $code, string $title = 'Programa', string $category = 'Programas Ejecutivos'): string
{
    $cat = $category !== '' ? " · Categoria: {$category}" : '';

    return "# {$title}\n<!-- Codigo: {$code} · Idioma: es{$cat} · Prioridad: 5 -->\n\n## Resumen\nContenido del programa {$code}.";
}

// --- Acceso ------------------------------------------------------------------

it('un no-Admin NO accede al Centro de Conocimiento', function () {
    $inst = libInstitution();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    test()->actingAs($marketing)->get('/centro-conocimiento')->assertForbidden();
});

it('un Admin accede al Centro de Conocimiento', function () {
    $inst = libInstitution();
    test()->actingAs(libAdmin($inst->id))->get('/centro-conocimiento')->assertOk();
});

// --- Validación de subida ----------------------------------------------------

it('sube un .md válido: crea la fuente en biblioteca/{categoria}/ y reporta Nuevo', function () {
    $inst = libInstitution();
    Storage::fake('knowledge');

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('PE-001.md', md('PE-001'))])
        ->call('uploadDocs')
        ->assertHasNoErrors()
        ->assertSee('Nuevo');

    expect(KnowledgeSource::query()->where('code', 'PE-001')->count())->toBe(1);
    expect(Storage::disk('knowledge')->exists('biblioteca/programas_ejecutivos/PE-001.md'))->toBeTrue();
    $src = KnowledgeSource::query()->where('code', 'PE-001')->firstOrFail();
    expect($src->category)->toBe('programas_ejecutivos'); // destino por categoría
    expect($src->bot_id)->toBeNull();                     // biblioteca central
});

it('rechaza un archivo sin comentario Codigo', function () {
    $inst = libInstitution();
    Storage::fake('knowledge');

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('x.md', "# Sin codigo\n\n## Sec\nTexto.")])
        ->call('uploadDocs')
        ->assertSee('Rechazado')
        ->assertSee('Codigo');

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('rechaza un archivo sin ninguna sección "##"', function () {
    $inst = libInstitution();
    Storage::fake('knowledge');

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('y.md', "# Titulo\n<!-- Codigo: NO-SEC · Idioma: es -->\n\nTexto sin secciones.")])
        ->call('uploadDocs')
        ->assertSee('Rechazado');

    expect(KnowledgeSource::query()->where('code', 'NO-SEC')->count())->toBe(0);
});

it('rechaza un archivo que no es .md', function () {
    $inst = libInstitution();
    Storage::fake('knowledge');

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('nota.txt', md('TXT-1'))])
        ->call('uploadDocs')
        ->assertSee('Rechazado')
        ->assertSee('.md');

    expect(KnowledgeSource::query()->count())->toBe(0);
});

// --- Upsert sin duplicar -----------------------------------------------------

it('mismo código con otro nombre de archivo: una sola fila y un solo archivo en disco', function () {
    $inst = libInstitution();
    Storage::fake('knowledge');
    $admin = libAdmin($inst->id);

    Livewire::actingAs($admin)->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('pe-001-v1.md', md('PE-001'))])
        ->call('uploadDocs');
    Livewire::actingAs($admin)->test(Library::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('pe-001-v2.md', md('PE-001', 'Programa v2'))])
        ->call('uploadDocs')
        ->assertSee('Actualizado');

    expect(KnowledgeSource::query()->where('code', 'PE-001')->count())->toBe(1);
    // Un solo archivo con ese código en disco (el anterior se reemplazó).
    $count = collect(Storage::disk('knowledge')->allFiles())->filter(fn ($f) => str_contains($f, 'pe-001'))->count();
    expect($count)->toBe(1);
});

// --- Toggle de estado --------------------------------------------------------

it('activa/desactiva el estado global de una fuente', function () {
    $inst = libInstitution();
    $src = KnowledgeSource::factory()->create(['institution_id' => $inst->id, 'bot_id' => null, 'code' => 'PE-T', 'status' => 'active']);

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)->call('toggleStatus', $src->id);
    expect($src->fresh()->status)->toBe('inactive');

    Livewire::actingAs(libAdmin($inst->id))->test(Library::class)->call('toggleStatus', $src->id);
    expect($src->fresh()->status)->toBe('active');
});

// --- Borrado con cascada + anti-resurrección ---------------------------------

it('borra la fuente (pivote en cascada + archivo) y NO reaparece tras sync legado ni syncLibrary', function () {
    $inst = libInstitution();
    $admin = libAdmin($inst->id);
    $celia = Bot::factory()->create(['institution_id' => $inst->id, 'status' => 'active', 'slug' => 'microcredenciales']);
    Storage::fake('knowledge');

    // Fuente legada: archivo en la carpeta del asesor + sync legado (crea fila + pivote).
    Storage::disk('knowledge')->put('microcredenciales/pe-del.md', md('PE-DEL'));
    app(KnowledgeSyncService::class)->sync($celia->id, 'microcredenciales');

    $src = KnowledgeSource::query()->where('code', 'PE-DEL')->firstOrFail();
    expect(\Illuminate\Support\Facades\DB::table('bot_knowledge_source')->where('knowledge_source_id', $src->id)->count())->toBe(1);

    // Borrado desde el panel.
    Livewire::actingAs($admin)->test(Library::class)
        ->call('confirmDelete', $src->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(KnowledgeSource::query()->where('code', 'PE-DEL')->count())->toBe(0);
    expect(\Illuminate\Support\Facades\DB::table('bot_knowledge_source')->where('knowledge_source_id', $src->id)->count())->toBe(0); // cascada
    expect(Storage::disk('knowledge')->exists('microcredenciales/pe-del.md'))->toBeFalse();                                          // archivo borrado

    // Anti-resurrección: ni el sync legado ni syncLibrary la recrean.
    app(KnowledgeSyncService::class)->sync($celia->id, 'microcredenciales');
    app(KnowledgeSyncService::class)->syncLibrary();
    expect(KnowledgeSource::query()->where('code', 'PE-DEL')->count())->toBe(0);
});
