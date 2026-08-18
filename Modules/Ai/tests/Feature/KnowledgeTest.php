<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeRetriever;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function knowledgeBot(): Bot
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create());
}

it('sincroniza los .md a knowledge_sources (upsert idempotente por codigo)', function () {
    Storage::fake('knowledge');
    Storage::disk('knowledge')->put('celia_kb_general_es.md',
        "# Conocimiento general\n<!-- Codigo: KB-MC-GENERAL-001 · Idioma: es · Prioridad: 10 -->\n\n## Que es\nUna microcredencial es una unidad academica.");

    $bot = knowledgeBot();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () use ($bot) {
        $service = new KnowledgeSyncService;

        $first = $service->sync($bot->getKey());
        expect($first['created'])->toBe(1);
        expect($first['updated'])->toBe(0);

        $source = KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->first();
        expect($source)->not->toBeNull();
        expect($source->priority)->toBe(10);
        expect($source->content_es)->toContain('unidad academica');
        expect($source->last_synced_at)->not->toBeNull();

        // Reejecutar NO duplica: actualiza.
        $second = $service->sync($bot->getKey());
        expect($second['created'])->toBe(0);
        expect($second['updated'])->toBe(1);
        expect(KnowledgeSource::query()->count())->toBe(1);
    });
});

it('reemplazar el .md se refleja al sincronizar', function () {
    Storage::fake('knowledge');
    Storage::disk('knowledge')->put('kb.md', "# KB\n<!-- Codigo: KB-1 · Idioma: es -->\n\n## A\nTexto viejo.");

    $bot = knowledgeBot();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () use ($bot) {
        (new KnowledgeSyncService)->sync($bot->getKey());

        Storage::disk('knowledge')->put('kb.md', "# KB\n<!-- Codigo: KB-1 · Idioma: es -->\n\n## A\nTexto nuevo actualizado.");
        (new KnowledgeSyncService)->sync($bot->getKey());

        $source = KnowledgeSource::query()->where('code', 'KB-1')->first();
        expect($source->content_es)->toContain('Texto nuevo actualizado');
        expect($source->content_es)->not->toContain('Texto viejo');
    });
});

it('recupera solo las secciones mas relevantes a la pregunta (acotado)', function () {
    $bot = knowledgeBot();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () use ($bot) {
        KnowledgeSource::factory()->create([
            'bot_id' => $bot->getKey(),
            'priority' => 5,
            'content_es' => "## Certificacion\nDiploma y certificado con verificacion digital.\n\n## Metodologia\nSeis semanas online a ritmo propio.\n\n## Inscripcion\nProceso online con cupones.",
            'status' => 'active',
        ]);

        $retriever = new KnowledgeRetriever;
        $text = $retriever->retrieve($bot->getKey(), '¿El certificado con verificacion?', 'es', 1);

        expect($text)->toContain('verificacion digital');
        // Acotado a 1 seccion: no arrastra las otras.
        expect($text)->not->toContain('cupones');
    });
});
