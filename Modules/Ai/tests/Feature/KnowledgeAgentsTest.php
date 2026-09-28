<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Configure;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Livewire\AdvisorSelector;
use Modules\Ai\Livewire\Knowledge\Agents;
use Modules\Ai\Livewire\Knowledge\Index as KnowledgeIndex;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Centro de Conocimiento — Bloque 3: selector de agente, pestaña Agentes y ficha del asesor
 * sobre el modelo de biblioteca + pivote.
 *
 * @return array{0: Institution, 1: User, 2: Bot, 3: Bot}
 */
function agentsCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $celia = Bot::factory()->create(['institution_id' => $inst->id, 'status' => 'active', 'slug' => 'microcredenciales', 'assistant_name' => 'Celia']);
    $lola = Bot::factory()->create(['institution_id' => $inst->id, 'status' => 'active', 'slug' => 'lola', 'assistant_name' => 'Lola']);

    return [$inst, $admin, $celia, $lola];
}

function libSource(int $instId, string $code, ?string $category = null): KnowledgeSource
{
    return KnowledgeSource::factory()->create([
        'institution_id' => $instId, 'bot_id' => null, 'code' => $code,
        'name' => 'Fuente '.$code, 'category' => $category, 'status' => 'active',
    ]);
}

function assignedIds(Bot $bot): array
{
    return $bot->knowledgeSources()->pluck('knowledge_sources.id')->sort()->values()->all();
}

// --- Selector de agente -------------------------------------------------------

it('el selector de agente fija el bot en sesión', function () {
    [, $admin, , $lola] = agentsCtx();

    Livewire::actingAs($admin)->test(AdvisorSelector::class)->set('botId', $lola->id);

    expect(session(SelectedAdvisor::SESSION_KEY))->toBe($lola->id);
    expect(SelectedAdvisor::current()->id)->toBe($lola->id);
});

it('el selector cambia lo que listan Advisor/Configure y Knowledge/Index', function () {
    [$inst, $admin, $celia, $lola] = agentsCtx();
    $a = libSource($inst->id, 'KB-CELIA');
    $b = libSource($inst->id, 'KB-LOLA');
    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $a);
    $assign->assignSource($lola, $b);

    // Sin selección: fallback al primer bot activo (Celia).
    Livewire::actingAs($admin)->test(Configure::class)->assertSee('KB-CELIA')->assertDontSee('KB-LOLA');
    Livewire::actingAs($admin)->test(KnowledgeIndex::class)->assertSee('KB-CELIA')->assertDontSee('KB-LOLA');

    // Con Lola seleccionada: se lista SOLO lo de Lola.
    SelectedAdvisor::set($lola->id);
    Livewire::actingAs($admin)->test(Configure::class)->assertSee('KB-LOLA')->assertDontSee('KB-CELIA');
    Livewire::actingAs($admin)->test(KnowledgeIndex::class)->assertSee('KB-LOLA')->assertDontSee('KB-CELIA')
        ->assertSee('Centro de Conocimiento'); // aviso visible de que la gestión está en el Centro
});

// --- Ficha del asesor -----------------------------------------------------------

it('subir desde la ficha de Lola asigna la fuente a Lola vía pivote, sin afectar a Celia', function () {
    [, $admin, $celia, $lola] = agentsCtx();
    Storage::fake('knowledge');

    $md = UploadedFile::fake()->createWithContent('lola-kb.md',
        "# Conocimiento Lola\n<!-- Codigo: KB-LOLA-1 · Idioma: es · Categoria: Programas Ejecutivos -->\n\n## Resumen\nTexto.");

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $lola])
        ->set('docs', [$md])->call('uploadKnowledge')->assertHasNoErrors();

    $src = KnowledgeSource::query()->where('code', 'KB-LOLA-1')->firstOrFail();
    expect($src->bot_id)->toBeNull();                                  // biblioteca central
    expect($src->category)->toBe('programas_ejecutivos');
    expect(assignedIds($lola))->toBe([$src->id]);                      // asignada a Lola
    expect(assignedIds($celia))->toBe([]);                             // Celia intacta
    Storage::disk('knowledge')->assertExists('biblioteca/programas_ejecutivos/lola-kb.md');
});

it('subir desde Advisor/Configure con Lola seleccionada asigna solo a Lola', function () {
    [, $admin, $celia, $lola] = agentsCtx();
    Storage::fake('knowledge');
    SelectedAdvisor::set($lola->id);

    Livewire::actingAs($admin)->test(Configure::class)
        ->set('docs', [UploadedFile::fake()->createWithContent('x.md', "# X\n<!-- Codigo: KB-X · Idioma: es -->\n\n## A\nB.")])
        ->call('uploadKnowledge')->assertHasNoErrors();

    $src = KnowledgeSource::query()->where('code', 'KB-X')->firstOrFail();
    expect(assignedIds($lola))->toBe([$src->id]);
    expect(assignedIds($celia))->toBe([]);
});

it('quitar un documento compartido desde la ficha NO lo borra ni afecta al otro agente', function () {
    [$inst, $admin, $celia, $lola] = agentsCtx();
    $shared = libSource($inst->id, 'KB-SHARED');
    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $shared);
    $assign->assignSource($lola, $shared);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $lola])->call('removeKnowledge', $shared->id)->assertHasNoErrors();

    expect(KnowledgeSource::query()->whereKey($shared->id)->exists())->toBeTrue(); // no se borró
    expect(assignedIds($lola))->toBe([]);                                          // quitada de Lola
    expect(assignedIds($celia))->toBe([$shared->id]);                              // Celia la conserva
});

// --- Pestaña Agentes ------------------------------------------------------------

it('pestaña Agentes: "usar toda la categoría" asigna todas sus fuentes (y la quita al repetir)', function () {
    [$inst, $admin, $celia, $lola] = agentsCtx();
    $p1 = libSource($inst->id, 'PE-001', 'programas_ejecutivos');
    $p2 = libSource($inst->id, 'PE-002', 'programas_ejecutivos');
    $other = libSource($inst->id, 'EST-001', 'estancias');
    SelectedAdvisor::set($lola->id);

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleCategory', 'programas_ejecutivos')->assertHasNoErrors();
    expect(assignedIds($lola))->toBe(collect([$p1->id, $p2->id])->sort()->values()->all()); // toda la categoría, nada más
    expect(assignedIds($celia))->toBe([]);

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleCategory', 'programas_ejecutivos');
    expect(assignedIds($lola))->toBe([]); // al repetir con la categoría completa, se quita
    expect(KnowledgeSource::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('pestaña Agentes: "usar toda la categoría" funciona también para Sin categoría', function () {
    [$inst, $admin, , $lola] = agentsCtx();
    $n1 = libSource($inst->id, 'KB-N1');
    $n2 = libSource($inst->id, 'KB-N2');
    SelectedAdvisor::set($lola->id);

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleCategory', KnowledgeAssignmentService::NO_CATEGORY);

    expect(assignedIds($lola))->toBe(collect([$n1->id, $n2->id])->sort()->values()->all());
});

it('pestaña Agentes: el interruptor individual asigna, pausa y reactiva', function () {
    [$inst, $admin, , $lola] = agentsCtx();
    $src = libSource($inst->id, 'PE-T', 'programas_ejecutivos');
    SelectedAdvisor::set($lola->id);
    $pivot = fn () => $lola->knowledgeSources()->where('knowledge_sources.id', $src->id)->first()?->pivot;

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleSource', $src->id);
    expect((bool) $pivot()->is_active)->toBeTrue();   // asignada y activa

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleSource', $src->id);
    expect((bool) $pivot()->is_active)->toBeFalse();  // pausada (sigue asignada)

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleSource', $src->id);
    expect((bool) $pivot()->is_active)->toBeTrue();   // reactivada
});

it('pestaña Agentes: Quitar desasigna solo del agente actual, sin borrar la fuente ni afectar a otros', function () {
    [$inst, $admin, $celia, $lola] = agentsCtx();
    $shared = libSource($inst->id, 'PE-S', 'programas_ejecutivos');
    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $shared);
    $assign->assignSource($lola, $shared);
    SelectedAdvisor::set($lola->id);

    Livewire::actingAs($admin)->test(Agents::class)
        ->assertSee('Compartida con: Celia')
        ->call('detachSource', $shared->id)->assertHasNoErrors();

    expect(assignedIds($lola))->toBe([]);
    expect(assignedIds($celia))->toBe([$shared->id]);
    expect(KnowledgeSource::query()->whereKey($shared->id)->exists())->toBeTrue();
});

// --- Acceso -----------------------------------------------------------------------

it('un no-Admin NO accede a la pestaña Agentes', function () {
    [$inst] = agentsCtx();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    test()->actingAs($marketing)->get('/centro-conocimiento/agentes')->assertForbidden();
});

it('un Admin accede a la pestaña Agentes', function () {
    [, $admin] = agentsCtx();

    test()->actingAs($admin)->get('/centro-conocimiento/agentes')->assertOk();
});
