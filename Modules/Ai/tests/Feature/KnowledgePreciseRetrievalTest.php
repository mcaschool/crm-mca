<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\CeliaService;
use Modules\Ai\Services\KnowledgeRetriever;
use Modules\Ai\Services\PreciseKnowledgeRanker;
use Modules\Ai\Support\KnowledgeText;
use Modules\Ai\Support\LinkGuard;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Búsqueda «precisa» en el conocimiento (activable por asesor): variantes de palabras, siglas,
 * rareza (BM25), título doble, preámbulos vacíos fuera, desempate determinista, programa
 * nombrado, tema activo de los seguimientos, filtro de enlaces, diagnóstico solo en la prueba y
 * límite de respuestas por asesor. La búsqueda clásica (Celia) no cambia.
 */
const PKR_DA_ADMISSIONS = 'https://mcaschool.education/es/admisiones/admisiones-diplomas-avanzados/';

/** Tres líneas con secciones casi idénticas (el caso difícil) y dos fichas de programa. */
function pkrSources(): array
{
    $general = fn (string $code, string $category, string $name, int $cuotas, string $url) => [
        'code' => $code, 'priority' => 6, 'category' => $category, 'type' => 'base_conocimiento',
        'content' => "<!-- Codigo: {$code} -->\n# Información general — {$name}\n\n"
            ."## Información general: qué es {$name}\n{$name} es un programa de educación continua online.\n\n"
            ."## Pagos en cuotas: ¿en cuántos pagos puedo pagar {$name}?\n{$name} se puede pagar hasta en {$cuotas} pagos. Precio y opciones en {$url} .\n\n"
            ."## Cómo verificar un diploma o credencial de {$name}\nCada diploma incluye un código de registro único para su verificación pública.",
    ];

    return [
        $general('DA-INFORMACION-GENERAL', 'diplomas_avanzados', 'el Diploma Avanzado', 3, PKR_DA_ADMISSIONS),
        $general('MMBA-INFORMACION-GENERAL', 'micro_mba', 'el Micro MBA', 4, 'https://mcaschool.education/es/estudios/micro-mba/'),
        $general('PE-FAQ', 'programas_ejecutivos', 'el Programa Ejecutivo', 2, 'https://mcaschool.education/es/admisiones/admisiones-formacion-ejecutiva/'),
        ['code' => 'DA-MARKETING', 'priority' => 3, 'category' => 'diplomas_avanzados', 'type' => 'programa_academico',
            'content' => "# Estrategia Comercial con Énfasis en Marketing Corporativo — Diploma Avanzado\n\n## Qué es el Diploma Avanzado en Estrategia Comercial con Énfasis en Marketing Corporativo\nIntegra la mercadotecnia estratégica y la gestión comercial.\n\n## Para quién es\nDirectivos comerciales y de marketing."],
        ['code' => 'DA-COACHING', 'priority' => 3, 'category' => 'diplomas_avanzados', 'type' => 'programa_academico',
            'content' => "# Coaching y Gestión de Equipos en Alto Desempeño — Diploma Avanzado\n\n## Qué es el Diploma Avanzado en Coaching\nCoaching aplicado a equipos.\n\n## Para quién es el Diploma Avanzado en Coaching\nLíderes de equipos."],
    ];
}

/** @return list<string> códigos de las secciones elegidas, en orden */
function pkrRank(string $question, array $history = [], ?array $sources = null): array
{
    $r = app(PreciseKnowledgeRanker::class)->rank($sources ?? pkrSources(), $question, $history, 3);

    return array_map(fn (array $s): string => $s['code'].' | '.ltrim($s['title'], '# '), $r['sections']);
}

// ─────────────────────────────── Texto

it('normaliza variantes y plurales del español sin librerías: verifico/verificar/verificación, pagos/pagar, financiera/financieros', function () {
    $stem = fn (string $w) => KnowledgeText::stem($w);

    expect(array_unique(array_map($stem, ['verifico', 'verificar', 'verificacion', 'verificable'])))->toHaveCount(1)
        ->and($stem('pagos'))->toBe($stem('pagar'))->toBe($stem('pago'))
        ->and($stem('financiera'))->toBe($stem('financieros'))
        ->and($stem('aprendo'))->toBe($stem('aprenderas'))
        ->and($stem('diplomas'))->toBe($stem('diploma'));
});

it('quita palabras vacías ES/EN, conserva siglas cortas configurables y expande DA/PE solo en mayúsculas', function () {
    expect(KnowledgeText::queryTerms('Hola, ¿puedes darme información del MBA?'))->toBe(['inform', 'mba'])
        ->and(KnowledgeText::queryTerms('Me da información'))->not->toContain('diplom')          // «da» verbo
        ->and(KnowledgeText::queryTerms('¿Cuánto cuesta un DA?'))->toContain('diplom')->toContain('avanz')
        ->and(KnowledgeText::linesMentioned('¿y el PE?'))->toBe(['programas_ejecutivos'])
        ->and(KnowledgeText::linesMentioned('pe'))->toBe([]);

    config(['crm.knowledge.retrieval.short_terms' => ['mba', 'erp']]);
    expect(KnowledgeText::queryTerms('implantar un ERP'))->toContain('erp');
});

// ─────────────────────────────── Ranker

it('descarta los preámbulos vacíos, pondera el título doble y la rareza de cada palabra', function () {
    $sources = [['code' => 'X', 'priority' => 1, 'category' => null, 'type' => 'base_conocimiento', 'content' => "<!-- c -->\n# Documento X\n\n## Horario\nEl campus abre siempre.\n\n## Campus virtual\nAcceso al campus con usuario."]];

    $sections = app(PreciseKnowledgeRanker::class)->rank($sources, 'información del documento campus', [], 5)['sections'];
    expect(array_column($sections, 'title'))->not->toContain('')            // el preámbulo vacío no compite
        ->and($sections[0]['title'])->toBe('## Campus virtual');              // en el título pesa el doble

    // Un preámbulo CON contenido sí cuenta.
    $withIntro = [['code' => 'Y', 'priority' => 1, 'category' => null, 'type' => 'base_conocimiento', 'content' => "# Doc\nIntroducción sobre becas disponibles.\n\n## Otra\nNada."]];
    expect(app(PreciseKnowledgeRanker::class)->rank($withIntro, 'becas', [], 3)['sections'][0]['body'])->toContain('becas');
});

it('el desempate es determinista: el orden en que llegan las fuentes no cambia el resultado', function () {
    $sources = pkrSources();
    $expected = pkrRank('¿en cuántos pagos?', [], $sources);
    foreach (range(1, 5) as $_) {
        shuffle($sources);
        expect(pkrRank('¿en cuántos pagos?', [], $sources))->toBe($expected);
    }
});

it('tema activo: en un seguimiento, la sección de la línea de la conversación queda PRIMERA entre las casi idénticas', function () {
    expect(pkrRank('En cuantos pagos puedo diferir los estudios', ['Hola, información de los Diplomas Avanzados'])[0])->toStartWith('DA-INFORMACION-GENERAL | Pagos en cuotas')
        ->and(pkrRank('en cuantos pagos', ['es un MBA de verdad?', 'Que es el micro MBA'])[0])->toStartWith('MMBA-INFORMACION-GENERAL | Pagos en cuotas')
        ->and(pkrRank('en cuantas cuotas lo puedo pagar', ['Que es un programa ejecutivo'])[0])->toStartWith('PE-FAQ | Pagos en cuotas')
        // La línea nombrada en la pregunta manda sobre el tema anterior.
        ->and(pkrRank('¿y el Micro MBA en cuántos pagos?', ['información de los Diplomas Avanzados'])[0])->toStartWith('MMBA-INFORMACION-GENERAL');

    // «Como verifico mi diploma» tras hablar de Diplomas Avanzados → la de DA, por variantes y tema.
    expect(pkrRank('Como verifico mi diploma', ['Que recibo al terminar un diploma avanzado'])[0])->toBe('DA-INFORMACION-GENERAL | Cómo verificar un diploma o credencial de el Diploma Avanzado');
});

it('programa nombrado: la ficha del programa prima sobre las secciones generales', function () {
    expect(pkrRank('Tienen algún diploma de marketing?')[0])->toStartWith('DA-MARKETING | Qué es el Diploma Avanzado en Estrategia Comercial')
        ->and(pkrRank('Para quien es el diploma avanzado de coaching')[0])->toBe('DA-COACHING | Para quién es el Diploma Avanzado en Coaching');
});

it('una pregunta que compara líneas no favorece a ninguna y «¿qué es…?» prefiere las secciones que definen', function () {
    $r = app(PreciseKnowledgeRanker::class)->rank(pkrSources(), 'Diferencia entre un programa ejecutivo y un diploma avanzado', [], 3);
    expect($r['diagnostics']['line'])->toBeNull();

    expect(pkrRank('Que es el micro MBA')[0])->toBe('MMBA-INFORMACION-GENERAL | Información general: qué es el Micro MBA');
});

// ─────────────────────────────── Recuperador: por asesor

/** Asesor con las fuentes de pkrSources() asignadas (y el modo de búsqueda pedido). */
function pkrBot(string $mode, array $attrs = []): Bot
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->withOwnIdentity(['assistant_name' => 'Sophia', 'instructions' => 'Asesora de líneas ejecutivas.', 'knowledge_retrieval' => $mode] + $attrs)->create();
    foreach (pkrSources() as $s) {
        $source = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => $s['code'], 'priority' => $s['priority'], 'category' => $s['category'], 'type' => $s['type'], 'content_es' => $s['content'], 'status' => 'active']);
        $source->bots()->attach($bot->getKey(), ['is_active' => true]);
    }

    return $bot;
}

it('la búsqueda clásica (Celia) no cambia: sin diagnóstico y con su orden de siempre; la precisa es por asesor', function () {
    $classic = pkrBot(Bot::RETRIEVAL_CLASSIC);
    $c = app(KnowledgeRetriever::class)->retrieveWithSources($classic->id, 'Hola puedes darme información', 'es', 3, ['Diplomas Avanzados']);
    // La clásica deja entrar el preámbulo vacío (su comportamiento de siempre) y no usa historial.
    expect($c)->not->toHaveKey('diagnostics')->and($c['text'])->toContain('# Información general —');

    $precise = pkrBot(Bot::RETRIEVAL_PRECISE);
    $p = app(KnowledgeRetriever::class)->retrieveWithSources($precise->id, 'en cuantos pagos', 'es', 3, ['información de los Diplomas Avanzados']);
    expect($p['diagnostics'])->toMatchArray(['line' => 'diplomas_avanzados', 'line_source' => 'conversation'])
        ->and($p['sources'][0])->toBe('DA-INFORMACION-GENERAL')
        ->and($p['text'])->not->toContain('<!-- Codigo');
});

// ─────────────────────────────── CeliaService

function pkrAi(Bot $bot, string $reply): FakeAiChatClient
{
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $fake = new FakeAiChatClient(json_encode(['reply' => $reply, 'action' => 'answer'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    app()->instance(AiChatClient::class, $fake);

    return $fake;
}

function pkrConversation(Bot $bot, string $channel = 'preview'): Conversation
{
    return Conversation::query()->forceCreate([
        'institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'session_id' => (string) Str::uuid(), 'channel' => $channel,
        'mode' => 'celia', 'language' => 'es', 'is_test' => $channel === 'preview', 'status' => 'open', 'started_at' => now(), 'last_activity_at' => now(),
    ]);
}

it('filtro de enlaces: solo quedan las URL que están textualmente en lo recuperado; la clásica no filtra', function () {
    $bot = pkrBot(Bot::RETRIEVAL_PRECISE);
    $fake = pkrAi($bot, 'Hasta en 3 pagos: '.PKR_DA_ADMISSIONS.' . Más detalles aquí: https://mcaschool.education/es/microcredenciales/');
    $conversation = pkrConversation($bot);
    app(CeliaService::class)->handle($conversation, null, 'Hola, información de los Diplomas Avanzados', 'es');
    $out = app(CeliaService::class)->handle($conversation, null, 'En cuantos pagos puedo diferir los estudios', 'es');

    expect($out['reply'])->toContain(PKR_DA_ADMISSIONS)->not->toContain('microcredenciales')->toEndWith('Más detalles aquí.')
        ->and(Message::query()->where('sender_type', 'celia')->latest('id')->first()->meta)->toMatchArray(['links_removed' => 1])
        // El prompt del seguimiento llevó la sección de cuotas de Diplomas Avanzados (tema activo).
        ->and($fake->calls[1]['messages'][0]['content'])->toContain('el Diploma Avanzado se puede pagar hasta en 3 pagos');

    $classic = pkrBot(Bot::RETRIEVAL_CLASSIC, ['assistant_name' => 'Otro']);
    pkrAi($classic, 'Mira https://inventado.example/x');
    expect(app(CeliaService::class)->handle(pkrConversation($classic), null, 'pagos', 'es')['reply'])->toContain('https://inventado.example/x');
});

it('el diagnóstico de la búsqueda solo se guarda en la conversación de prueba, nunca en un canal real', function () {
    $bot = pkrBot(Bot::RETRIEVAL_PRECISE);
    pkrAi($bot, 'Hasta en 3 pagos.');

    app(CeliaService::class)->handle($preview = pkrConversation($bot, 'preview'), null, '¿En cuántos pagos el diploma avanzado?', 'es');
    app(CeliaService::class)->handle($web = pkrConversation($bot, 'web'), null, '¿En cuántos pagos el diploma avanzado?', 'es');

    $metaOf = fn (Conversation $c) => Message::query()->where('conversation_id', $c->id)->where('sender_type', 'celia')->first()->meta;
    expect($metaOf($preview)['retrieval'])->toHaveKeys(['terms', 'line', 'top'])
        ->and($metaOf($preview)['retrieval']['top'][0]['code'])->toBe('DA-INFORMACION-GENERAL')
        ->and($metaOf($web))->not->toHaveKey('retrieval');
});

it('el límite de respuestas de IA es por asesor; sin valor propio se usa el general', function () {
    $bot = pkrBot(Bot::RETRIEVAL_PRECISE, ['ai_message_limit' => 2]);
    pkrAi($bot, 'Respuesta.');
    $conversation = pkrConversation($bot);
    $celia = app(CeliaService::class);
    $celia->handle($conversation, null, 'uno', 'es');
    $second = $celia->handle($conversation, null, 'dos', 'es');
    $third = $celia->handle($conversation, null, 'tres', 'es');

    expect($second['messages_left'])->toBe(0)->and($third['action'])->toBe('limit')->and($third['limit_reached'])->toBeTrue();

    config(['crm.celia.message_limit' => 1]);
    $general = pkrBot(Bot::RETRIEVAL_CLASSIC, ['assistant_name' => 'General']);
    pkrAi($general, 'Respuesta.');
    $c2 = pkrConversation($general);
    $celia->handle($c2, null, 'uno', 'es');
    expect($celia->handle($c2, null, 'dos', 'es')['action'])->toBe('limit')
        ->and($general->aiMessageLimit())->toBe(1)->and($bot->fresh()->aiMessageLimit())->toBe(2);
});

it('LinkGuard: misma URL con o sin barra final; quita las desconocidas sin dejar puntuación colgando', function () {
    [$ok] = LinkGuard::keepKnown('Ver https://a.example/x/ ahora.', 'texto https://a.example/x .');
    [$bad, $removed] = LinkGuard::keepKnown('Inscríbete aquí: https://b.example/y/ .', 'nada');

    expect($ok)->toBe('Ver https://a.example/x/ ahora.')->and($bad)->toBe('Inscríbete aquí.')->and($removed)->toBe(['https://b.example/y/']);
});

// ─────────────────────────────── Ficha del asesor

it('la ficha del asesor guarda el modo de búsqueda y el límite de respuestas (vacío = general)', function () {
    $bot = pkrBot(Bot::RETRIEVAL_CLASSIC);
    $admin = User::factory()->create(['institution_id' => $bot->institution_id, 'role' => 'admin']);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSet('knowledgeRetrieval', 'classic')->assertSet('messageLimit', '')
        ->set('knowledgeRetrieval', 'precise')->set('messageLimit', '40')->call('save')->assertHasNoErrors();
    expect($bot->fresh()->only(['knowledge_retrieval', 'ai_message_limit']))->toBe(['knowledge_retrieval' => 'precise', 'ai_message_limit' => 40]);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot->fresh()])
        ->set('messageLimit', '0')->call('save')->assertHasErrors(['messageLimit'])
        ->set('knowledgeRetrieval', 'otra')->set('messageLimit', '')->call('save')->assertHasErrors(['knowledgeRetrieval']);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot->fresh()])->set('messageLimit', '')->call('save');
    expect($bot->fresh()->ai_message_limit)->toBeNull();
});
