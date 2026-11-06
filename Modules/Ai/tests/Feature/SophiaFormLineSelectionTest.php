<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\CeliaService;
use Modules\Ai\Services\PreciseKnowledgeRanker;
use Modules\Ai\Support\KnowledgeText;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Selección de línea académica cuando el mensaje es un FORMULARIO (Meta Lead Ad): los datos de
 * PERFIL (nombre, país, cargo, último nivel de estudios) no deben competir con el INTERÉS académico
 * para elegir la línea. Caso real: formulario de Microcredenciales en «Economía y Finanzas» con
 * «Maestría o posgrado» como último nivel de estudios terminaba recomendando un Diploma Avanzado.
 */

/** KB multilínea tipo Sophia: base de Microcredenciales + una microcredencial de Economía + una ficha
 *  de Diploma Avanzado de Economía (la que se elegía por error) + base institucional. */
function sflSources(): array
{
    return [
        ['code' => 'INST-GENERAL', 'priority' => 6, 'category' => 'general_institucional', 'type' => 'base_conocimiento',
            'content' => "# Información institucional de MCA School\n\n## Formas de pago en MCA School\nTarjeta y transferencia."],
        ['code' => 'KB-MC-GENERAL-001', 'priority' => 6, 'category' => 'microcredenciales', 'type' => 'base_conocimiento',
            'content' => "# Conocimiento general de Microcredenciales\n\n## Qué es una microcredencial\nCertificación corta y aplicada.\n\n## Inscripción y proceso\nInscripción en línea; admisión sin título universitario."],
        ['code' => 'MC-ECO', 'priority' => 3, 'category' => 'microcredenciales', 'type' => 'programa_academico',
            'content' => "# Economía y Finanzas para la Gestión — Microcredencial\n\n## Qué es la microcredencial de Economía y Finanzas\nFundamentos de economía y finanzas aplicadas.\n\n## Qué aprenderás\nAnálisis económico y financiero para decisiones."],
        ['code' => 'DA-ECONOMIA-FINANZAS-CORPORATIVAS', 'priority' => 6, 'category' => 'diplomas_avanzados', 'type' => 'programa_academico',
            'content' => "# Economía y Finanzas Corporativas — Diploma Avanzado\n\n## Qué es el Diploma Avanzado en Economía y Finanzas Corporativas\nGestión económica y financiera de la empresa.\n\n## Para quién es\nDirectivos financieros."],
    ];
}

/** El formulario EXACTO que falló (con saltos de línea). */
function sflForm(): string
{
    return "¡Hola! Completé el formulario y me gustaría obtener más información sobre el programa.\n"
        ."Full name: Carlos Martínez\n"
        ."¿A qué categoría pertenece la microcredencial de tu interés?: Economía y Finanzas\n"
        ."Country: US\n"
        ."¿Qué cargo ocupas actualmente?: Jefe o coordinador\n"
        ."¿Cuál es tu último nivel de estudios?: Maestría o posgrado";
}

it('REPRODUCE el fallo si no se separa el perfil: «Maestría» (nivel de estudios) hace el tema ambiguo', function () {
    // Sin limpiar el formulario, el texto menciona dos líneas: microcredenciales (interés) y maestrias
    // (del campo de perfil «último nivel de estudios»), de modo que el tema queda ambiguo.
    $lines = KnowledgeText::linesMentioned(sflForm());
    expect($lines)->toContain('microcredenciales')->toContain('maestrias');
});

it('caso real: el formulario de Microcredenciales en Economía y Finanzas recupera Microcredenciales, nunca Diplomas Avanzados', function () {
    $r = app(PreciseKnowledgeRanker::class)->rank(sflSources(), sflForm(), [], 3);
    $codes = array_column($r['sections'], 'code');

    expect($r['diagnostics']['line'])->toBe('microcredenciales')
        ->and($codes)->not->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS')
        ->and($r['diagnostics']['focus_program'])->not->toBe('DA-ECONOMIA-FINANZAS-CORPORATIVAS')
        ->and(implode(',', $codes))->toContain('MC');   // alguna fuente de Microcredenciales
});

/** @return list<string> */
function sflLine(string $question, array $history = []): array
{
    return app(PreciseKnowledgeRanker::class)->rank(sflSources(), $question, $history, 3)['diagnostics'];
}

it('variante SIN saltos de línea (formulario aplanado): misma línea microcredenciales, sin Diplomas Avanzados', function () {
    $inline = str_replace("\n", ' ', sflForm());
    $r = app(PreciseKnowledgeRanker::class)->rank(sflSources(), $inline, [], 3);
    expect($r['diagnostics']['line'])->toBe('microcredenciales')
        ->and(array_column($r['sections'], 'code'))->not->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS');
});

it('distinto cargo y estudios universitarios: el perfil no cambia la línea', function () {
    $form = "¡Hola! Completé el formulario.\nFull name: Ana Ruiz\n¿A qué categoría pertenece la microcredencial de tu interés?: Economía y Finanzas\nCountry: MX\n¿Qué cargo ocupas actualmente?: Analista\n¿Cuál es tu último nivel de estudios?: Universitario completo";
    expect(sflLine($form)['line'])->toBe('microcredenciales');
});

it('preserva la línea legítima: si la categoría del formulario es Diplomas Avanzados, esa es la línea', function () {
    $form = "¡Hola! Completé el formulario.\nFull name: Luis P\n¿A qué categoría pertenece el diploma avanzado de tu interés?: Economía y Finanzas\n¿Cuál es tu último nivel de estudios?: Maestría o posgrado";
    $r = app(PreciseKnowledgeRanker::class)->rank(sflSources(), $form, [], 3);
    expect($r['diagnostics']['line'])->toBe('diplomas_avanzados')
        ->and(array_column($r['sections'], 'code'))->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS');
});

it('formulario SIN interés académico declarado (solo perfil): no fuerza ninguna línea de programa', function () {
    $form = "¡Hola! Completé el formulario.\nFull name: Pedro G\nCountry: US\n¿Cuál es tu último nivel de estudios?: Maestría o posgrado";
    $d = sflLine($form);
    expect($d['line'])->toBeNull()                          // ni maestrias (perfil) ni otra
        ->and($d['focus_program'])->toBeNull();
});

it('comparación explícita (no formulario) se mantiene: no favorece ninguna línea', function () {
    expect(sflLine('¿Qué diferencia hay entre una microcredencial y un diploma avanzado?')['line'])->toBeNull();
});

it('pregunta de seguimiento tras el formulario: hereda la línea microcredenciales del contexto', function () {
    $d = sflLine('¿cuánto dura?', [sflForm()]);
    expect($d['line'])->toBe('microcredenciales');
});

// ── Respuesta final simulando el modelo (end-to-end por CeliaService)

function sflBot(): Bot
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->withOwnIdentity(['assistant_name' => 'Sophia', 'instructions' => 'Asesora de varias líneas.', 'knowledge_retrieval' => Bot::RETRIEVAL_PRECISE])->create();
    foreach (sflSources() as $s) {
        $src = \Modules\Ai\Models\KnowledgeSource::factory()->create(['bot_id' => null, 'code' => $s['code'], 'priority' => $s['priority'], 'category' => $s['category'], 'type' => $s['type'], 'content_es' => $s['content'], 'status' => 'active']);
        $src->bots()->attach($bot->getKey(), ['is_active' => true]);
    }

    return $bot;
}

it('end-to-end: Sophia responde con conocimiento de Microcredenciales y NINGUNA fuente de Diplomas Avanzados', function () {
    $bot = sflBot();
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    app()->instance(AiChatClient::class, new FakeAiChatClient(json_encode(['reply' => 'Sobre las Microcredenciales en Economía y Finanzas…', 'action' => 'answer'], JSON_UNESCAPED_UNICODE)));

    $conversation = Conversation::query()->forceCreate([
        'institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'session_id' => (string) Str::uuid(), 'channel' => 'preview',
        'mode' => 'celia', 'language' => 'es', 'is_test' => true, 'status' => 'open', 'started_at' => now(), 'last_activity_at' => now(),
    ]);

    app(CeliaService::class)->handle($conversation, null, sflForm(), 'es');

    $meta = Message::query()->where('conversation_id', $conversation->id)->where('sender_type', 'celia')->latest('id')->first()->meta;
    expect($meta['knowledge_sources'] ?? [])->not->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS')
        ->and($meta['retrieval']['line'] ?? null)->toBe('microcredenciales');
});

// ── Etiquetas de INTERÉS con palabras ambiguas («nombre», «país», «número») no deben descartarse

it('formAcademicText conserva la pregunta de interés con «nombre» y descarta solo los campos de perfil', function () {
    $form = "¡Hola! Completé el formulario.\n"
        ."Full name: María López\n"
        ."¿Cuál es el nombre de la microcredencial que te interesa?: Finanzas Corporativas\n"
        ."Country: ES\n"
        ."¿Cuál es tu último nivel de estudios?: Maestría o posgrado";
    $clean = KnowledgeText::formAcademicText($form);

    expect($clean)->toContain('microcredencial')->toContain('Finanzas Corporativas')   // interés conservado
        ->not->toContain('María López')                                                 // nombre personal fuera
        ->not->toContain('Maestría')                                                    // nivel de estudios fuera
        ->not->toContain('ES');                                                         // país fuera (país: ES)
});

it('interés «nombre de la microcredencial»: línea microcredenciales, nunca Diplomas Avanzados (con y sin saltos)', function () {
    $form = "¡Hola! Completé el formulario.\nFull name: María López\n¿Cuál es el nombre de la microcredencial que te interesa?: Finanzas Corporativas\n¿Cuál es tu último nivel de estudios?: Maestría o posgrado";

    foreach ([$form, str_replace("\n", ' ', $form)] as $variant) {
        $r = app(PreciseKnowledgeRanker::class)->rank(sflSources(), $variant, [], 3);
        expect($r['diagnostics']['line'])->toBe('microcredenciales')
            ->and(array_column($r['sections'], 'code'))->not->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS');
    }
});

it('interés legítimo en otra línea con «nombre»: «nombre del diploma avanzado» mantiene Diplomas Avanzados', function () {
    $form = "¡Hola!\nFull name: Pedro G\n¿Cuál es el nombre del diploma avanzado que te interesa?: Economía y Finanzas\n¿Cuál es tu último nivel de estudios?: Universitario completo";
    expect(sflLine($form)['line'])->toBe('diplomas_avanzados');
});

it('el caso original de Carlos Martínez sigue intacto tras el ajuste', function () {
    $r = app(PreciseKnowledgeRanker::class)->rank(sflSources(), sflForm(), [], 3);
    expect($r['diagnostics']['line'])->toBe('microcredenciales')
        ->and(array_column($r['sections'], 'code'))->not->toContain('DA-ECONOMIA-FINANZAS-CORPORATIVAS');
});
