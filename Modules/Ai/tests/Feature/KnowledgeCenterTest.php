<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\KnowledgeRetriever;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Centro de Conocimiento (BLOQUE 1): biblioteca central compartible por pivote, sin romper
 * la recuperación Forma A.
 */
function kcInstitution(): Institution
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return $inst;
}

function kcBot(int $instId, string $slug): Bot
{
    return Bot::factory()->create(['institution_id' => $instId, 'status' => 'active', 'slug' => $slug]);
}

const KC_CONTENT = "# KB Microcredenciales\n\n## Horas académicas\nUna microcredencial tiene 60 horas académicas.\n\n## Metodología\nEs online y a ritmo propio.";

function kcSource(int $instId, ?int $botId, array $attrs = []): KnowledgeSource
{
    return KnowledgeSource::factory()->create(array_merge([
        'institution_id' => $instId,
        'bot_id' => $botId,
        'status' => 'active',
        'priority' => 0,
        'content_es' => KC_CONTENT,
        'content_en' => null,
    ], $attrs));
}

function kcRetrieve(int $botId): string
{
    return app(KnowledgeRetriever::class)->retrieve($botId, 'horas académicas de una microcredencial', 'es');
}

// 1) La migración conserva Celia ↔ KB y Celia recupera el mismo contexto (secciones).
it('tras la migración, Celia (KB asignada por pivote) recupera el mismo contexto', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    $kb = kcSource($inst->id, $celia->id, ['code' => 'KB-MC-GENERAL-001']);
    app(KnowledgeAssignmentService::class)->assignSource($celia, $kb); // como hace la migración de datos

    $ctx = kcRetrieve($celia->id);
    expect($ctx)->toContain('60 horas académicas')->toContain('Metodología');
});

// 2) Fuente compartida por Celia y Lola: se recupera para ambas SIN duplicar filas.
it('una fuente asignada a dos bots se recupera para ambos sin duplicar filas', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    $lola = kcBot($inst->id, 'lola');
    $kb = kcSource($inst->id, $celia->id, ['code' => 'KB-SHARED']);

    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $kb);
    $assign->assignSource($lola, $kb);

    expect(KnowledgeSource::query()->count())->toBe(1);            // una sola fila de fuente
    expect(DB::table('bot_knowledge_source')->count())->toBe(2);  // dos asignaciones
    expect(kcRetrieve($celia->id))->toContain('60 horas académicas');
    expect(kcRetrieve($lola->id))->toContain('60 horas académicas');
});

// 3) Pivote is_active=false excluye SOLO a ese bot; el otro sí la recupera.
it('is_active=false en el pivote excluye solo a ese bot', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    $lola = kcBot($inst->id, 'lola');
    $kb = kcSource($inst->id, $celia->id, ['code' => 'KB-TOGGLE']);

    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $kb);
    $assign->assignSource($lola, $kb);
    $assign->toggle($lola, $kb, false); // desactiva solo para Lola

    expect(kcRetrieve($celia->id))->toContain('60 horas académicas'); // Celia sí
    expect(kcRetrieve($lola->id))->toBe('');                          // Lola no
});

// 4) status inactive excluye a TODOS.
it('una fuente con status inactive no se recupera para nadie', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    $lola = kcBot($inst->id, 'lola');
    $kb = kcSource($inst->id, $celia->id, ['code' => 'KB-OFF', 'status' => 'inactive']);

    $assign = app(KnowledgeAssignmentService::class);
    $assign->assignSource($celia, $kb);
    $assign->assignSource($lola, $kb);

    expect(kcRetrieve($celia->id))->toBe('');
    expect(kcRetrieve($lola->id))->toBe('');
});

// 5) syncLibrary lee Categoria/Prioridad del comentario HTML (PE-) y aplica precedencia.
it('syncLibrary lee Categoria y Prioridad del comentario y respeta la precedencia carpeta/meta', function () {
    $inst = kcInstitution();
    Storage::fake('knowledge');

    // Con meta Categoria explícita: gana el meta (normalizado a slug).
    Storage::disk('knowledge')->put(
        'biblioteca/programas_ejecutivos/pe_001.md',
        "# Micro MBA\n<!-- Codigo: PE-001 · Idioma: es · Categoria: Programas Ejecutivos · Prioridad: 7 -->\n\n## Resumen\nContenido PE."
    );
    // Sin meta Categoria: cae al nombre de la carpeta.
    Storage::disk('knowledge')->put(
        'biblioteca/estancias/est_001.md',
        "# Estancia\n<!-- Codigo: EST-001 · Idioma: es -->\n\n## Resumen\nContenido estancia."
    );

    $report = app(KnowledgeSyncService::class)->syncLibrary();
    expect($report['created'])->toBe(2);

    $pe = KnowledgeSource::query()->where('code', 'PE-001')->firstOrFail();
    expect($pe->category)->toBe('programas_ejecutivos'); // del meta, normalizado
    expect($pe->priority)->toBe(7);                       // del meta
    expect($pe->bot_id)->toBeNull();                      // biblioteca central: sin bot

    $est = KnowledgeSource::query()->where('code', 'EST-001')->firstOrFail();
    expect($est->category)->toBe('estancias');            // del nombre de la carpeta
});

// 6) sync() legado sigue funcionando y adjunta al pivote del bot.
it('sync() legado crea la fuente con su bot_id y la adjunta al pivote', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    Storage::fake('knowledge');
    Storage::disk('knowledge')->put(
        'microcredenciales/kb.md',
        "# KB\n<!-- Codigo: KB-LEGACY · Idioma: es -->\n\n## Horas académicas\nTiene 60 horas académicas."
    );

    $report = app(KnowledgeSyncService::class)->sync($celia->id, 'microcredenciales');
    expect($report['created'])->toBe(1);

    $src = KnowledgeSource::query()->where('code', 'KB-LEGACY')->firstOrFail();
    expect($src->bot_id)->toBe($celia->id);
    expect(DB::table('bot_knowledge_source')->where('bot_id', $celia->id)->where('knowledge_source_id', $src->id)->exists())->toBeTrue();
    expect(kcRetrieve($celia->id))->toContain('60 horas académicas');
});

// Precisión A: mismo code sincronizado desde Celia y luego Lola → una fila, bot_id sigue
// Celia, pivote con ambos.
it('mismo code desde dos bots: una sola fila, bot_id no se pisa, pivote con ambos', function () {
    $inst = kcInstitution();
    $celia = kcBot($inst->id, 'microcredenciales');
    $lola = kcBot($inst->id, 'lola');
    Storage::fake('knowledge');

    $md = "# KB Común\n<!-- Codigo: KB-COMMON · Idioma: es -->\n\n## Horas académicas\nTiene 60 horas académicas.";
    Storage::disk('knowledge')->put('microcredenciales/kb.md', $md);
    Storage::disk('knowledge')->put('lola/kb.md', $md);

    $sync = app(KnowledgeSyncService::class);
    $sync->sync($celia->id, 'microcredenciales'); // crea (bot_id=Celia) + pivote Celia
    $sync->sync($lola->id, 'lola');               // NO pisa bot_id + pivote Lola

    expect(KnowledgeSource::query()->where('code', 'KB-COMMON')->count())->toBe(1);
    $src = KnowledgeSource::query()->where('code', 'KB-COMMON')->firstOrFail();
    expect($src->bot_id)->toBe($celia->id); // sigue siendo Celia
    expect(DB::table('bot_knowledge_source')->where('knowledge_source_id', $src->id)->count())->toBe(2);
});
