<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Preview;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\AdvisorCorrection;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorCatalog;
use Modules\Ai\Services\AdvisorCorrections;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\CeliaService;
use Modules\Ai\Support\TextLanguage;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Preguntas de CATÁLOGO y respuestas APROBADAS literales (mismo núcleo en web, «Probar asesor»,
 * WhatsApp y Messenger; los canales sociales se prueban en SocialAdvisorCatalogTest):
 *  - la lista sale de los programas ACTIVOS asignados al asesor y el enlace de knowledge_sources.url
 *    (https, «base_conocimiento» activa, asignada, de la línea, mayor prioridad), sin IA;
 *  - las respuestas aprobadas salen literalmente, sin IA, si el idioma coincide.
 *
 * Los tres mensajes de los casos reales se OBSERVARON en Messenger (conversación social 2); aquí
 * se reproducen como regresión en «Probar asesor» (y en WhatsApp/Messenger en el módulo Social).
 */
const ACAT_PE_LINK = 'https://example.test/es/estudios/programas-ejecutivos/';
const ACAT_MMBA_LINK = 'https://example.test/es/estudios/micro-mba/';

/** Casos reales observados en Messenger (texto tal cual, con sus faltas). */
const ACAT_REAL_186 = 'Que programas tiene disponibles';
const ACAT_REAL_196 = 'Que programas ejecutivos tuenen disponible';
const ACAT_REAL_198 = 'Estas seguro, puedes darme el link de donde ver los programas ejecutivos disponibles';

/** @return array{0: Institution, 1: Bot, 2: string, 3: FakeAiChatClient} */
function acatCtx(string $name = 'Sophia'): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->withOwnIdentity(['assistant_name' => $name, 'instructions' => 'Asesora de líneas ejecutivas.', 'knowledge_retrieval' => Bot::RETRIEVAL_PRECISE, 'status' => 'inactive', 'default_language' => 'es'])->create();
    acatCatalog($bot);
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $fake = new FakeAiChatClient('{"reply": "Respuesta del modelo.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, app(AdvisorPreviewLinkService::class)->generate($bot), $fake];
}

/**
 * Catálogo del asesor: 20 Programas Ejecutivos, 3 Diplomas Avanzados y 1 Micro MBA activos y
 * asignados. Fuera: un PE asignado pero inactivo y un PE activo sin asignar. Fuentes: dos
 * «base_conocimiento» de PE con enlace (gana la de mayor prioridad), una de DA SIN enlace y otras
 * que nunca dan el enlace (http, inactiva, ficha de programa, no asignada).
 */
function acatCatalog(Bot $bot): void
{
    $assigned = [];
    for ($i = 1; $i <= 20; $i++) {
        $assigned[] = Program::factory()->create(['code' => sprintf('PE-%02d', $i), 'name_es' => "Programa Ejecutivo en Gestión {$i}", 'name_en' => "Professional Certificate in Management {$i}", 'line' => 'programas_ejecutivos', 'display_order' => $i])->id;
    }
    foreach (['Diploma Avanzado en Finanzas', 'Diploma Avanzado en Marketing', 'Diploma Avanzado en Liderazgo'] as $i => $n) {
        $assigned[] = Program::factory()->create(['code' => 'DA-0'.$i, 'name_es' => $n, 'line' => 'diplomas_avanzados', 'display_order' => $i])->id;
    }
    $assigned[] = Program::factory()->create(['code' => 'MMBA-01', 'name_es' => 'Micro MBA', 'line' => 'micro_mba'])->id;
    $assigned[] = Program::factory()->create(['code' => 'PE-OFF', 'name_es' => 'Programa Ejecutivo Retirado', 'line' => 'programas_ejecutivos', 'status' => 'inactive'])->id;
    Program::factory()->create(['code' => 'PE-AJENO', 'name_es' => 'Programa Ejecutivo De Otro Asesor', 'line' => 'programas_ejecutivos']);
    $bot->programs()->sync($assigned);

    $source = function (string $code, string $line, ?string $url, int $priority, array $extra = [], bool $attach = true) use ($bot): KnowledgeSource {
        $s = KnowledgeSource::factory()->create($extra + ['bot_id' => null, 'code' => $code, 'category' => $line, 'type' => 'base_conocimiento', 'priority' => $priority, 'status' => 'active', 'url' => $url,
            'content_es' => "## Información general {$code}\nModalidad online con acompañamiento.\n\n## Costo y formas de pago {$code}\nSe paga en cuotas."]);
        if ($attach) {
            $s->bots()->attach($bot->id, ['is_active' => true]);
        }

        return $s;
    };
    $source('PE-FAQ', 'programas_ejecutivos', ACAT_PE_LINK, 8);
    $source('PE-MODELO', 'programas_ejecutivos', 'https://example.test/otra-pagina/', 5);              // menor prioridad
    $source('PE-HTTP', 'programas_ejecutivos', 'http://example.test/inseguro/', 10);                 // no https
    $source('PE-INACTIVA', 'programas_ejecutivos', 'https://example.test/inactiva/', 10, ['status' => 'inactive']);
    $source('PE-FICHA', 'programas_ejecutivos', 'https://example.test/ficha/', 10, ['type' => 'programa_academico']);
    $source('PE-OTRO-ASESOR', 'programas_ejecutivos', 'https://example.test/otro-asesor/', 10, [], false);
    $source('DA-INFO', 'diplomas_avanzados', null, 6);                                               // sin enlace confirmado
    $source('MMBA-INFO', 'micro_mba', ACAT_MMBA_LINK, 6);
}

function acatChat(string $token): Testable
{
    return Livewire::test(Preview::class, ['token' => $token])->call('start');
}

function acatLastReply(): Message
{
    return Message::query()->where('sender_type', 'celia')->orderByDesc('id')->first();
}

it('detecta las preguntas de catálogo (sin IA) y no confunde las demás', function (string $text, bool $expected) {
    expect(AdvisorCatalog::isCatalogQuestion($text))->toBe($expected);
})->with([
    'real 186' => [ACAT_REAL_186, true],
    'real 196' => [ACAT_REAL_196, true],
    'real 198' => [ACAT_REAL_198, true],
    'diplomas' => ['¿Qué Diplomas avanzados tienen disponibles?', true],
    'tipos' => ['¿Qué tipos de programas tienen?', true],
    'inglés' => ['What programs do you offer?', true],
    'lista' => ['Me pasas la lista de cursos', true],
    'costo' => ['¿Cuánto cuesta el Micro MBA?', false],
    'inicio' => ['¿Cuándo empiezan?', false],
    'información' => ['Puedes darme información sobre los programas ejecutivos', false],
    'título' => ['El título que entrega el Micro MBA es oficial', false],
    'clases' => ['Hay clases sincrónicas?', false],
]);

it('casos reales observados en Messenger, reproducidos en «Probar asesor»: resumen por líneas, los 20 ejecutivos y el enlace', function () {
    [, , $token, $fake] = acatCtx();
    $chat = acatChat($token);

    // 186 · sin línea: las líneas con su cantidad y su enlace, y una pregunta (nunca los 24 nombres).
    $chat->set('draft', ACAT_REAL_186)->call('send');
    $summary = acatLastReply();
    expect($summary->content)->toContain('Programas Ejecutivos: 20 programas — '.ACAT_PE_LINK)
        ->toContain('Diplomas Avanzados: 3 programas')
        ->toContain('Micro MBA: 1 programa — '.ACAT_MMBA_LINK)
        ->toContain('¿Cuál te gustaría consultar?')
        ->not->toContain('Gestión 1')
        ->and($summary->meta['action'])->toBe('catalog')
        ->and($summary->meta['catalog'])->toMatchArray(['mode' => 'summary', 'count' => 24, 'link_missing' => ['diplomas_avanzados']])
        ->and($summary->meta['catalog']['lines'])->toMatchArray(['programas_ejecutivos' => 20, 'diplomas_avanzados' => 3, 'micro_mba' => 1]);

    // 196 · con la falta «tuenen»: los 20 activos y asignados (ni el inactivo ni el ajeno) + enlace.
    $chat->set('draft', ACAT_REAL_196)->call('send');
    $list = acatLastReply();
    for ($i = 1; $i <= 20; $i++) {
        expect($list->content)->toContain("• Programa Ejecutivo en Gestión {$i}\n");
    }
    expect($list->content)->toStartWith('Estos son los 20 Programas Ejecutivos disponibles:')
        ->toContain('Puedes verlos todos aquí: '.ACAT_PE_LINK)
        ->not->toContain('Retirado')->not->toContain('Otro Asesor')->not->toContain('único')
        ->not->toContain('otra-pagina')->not->toContain('inseguro')
        ->and($list->meta['catalog'])->toMatchArray(['mode' => 'list', 'line' => 'programas_ejecutivos', 'count' => 20, 'link_source' => 'PE-FAQ', 'link_missing' => []])
        ->and($list->meta['knowledge_sources'])->toBe(['PE-FAQ']);

    // 198 · «¿estás seguro? ¿me das el link…?»: la misma lista con el enlace, nunca «no lo encuentro».
    $chat->set('draft', ACAT_REAL_198)->call('send');
    $again = acatLastReply();
    expect($again->content)->toBe($list->content)->and($again->meta['action'])->toBe('catalog');

    expect($fake->calls)->toHaveCount(0);        // todo determinista, sin IA
    $chat->assertSee('Catálogo (datos del sistema)');
});

it('tras el resumen, nombrar una línea responde su lista; una línea sin enlace confirmado responde sin enlace y lo registra', function () {
    [, , $token, $fake] = acatCtx();
    $chat = acatChat($token);

    $chat->set('draft', '¿Qué programas ofrecen?')->call('send');
    $chat->set('draft', 'los diplomas avanzados')->call('send');
    $da = acatLastReply();

    expect($da->content)->toStartWith('Estos son los 3 Diplomas Avanzados disponibles:')
        ->toContain('• Diploma Avanzado en Finanzas')->not->toContain('http')
        ->and($da->meta['catalog'])->toMatchArray(['mode' => 'list', 'line' => 'diplomas_avanzados', 'link_source' => null, 'link_missing' => ['diplomas_avanzados']])
        ->and($fake->calls)->toHaveCount(0);

    // Un solo programa en la línea: nunca «estos son los 1…».
    $chat->set('draft', '¿Qué programas de Micro MBA tienen?')->call('send');
    expect(acatLastReply()->content)->toStartWith('Este es el programa de Micro MBA disponible:')->toContain('Más información aquí: '.ACAT_MMBA_LINK);
});

it('si la lista no cabe en el límite seguro del canal, responde la versión compacta (nunca cortada)', function () {
    [, , $token] = acatCtx();
    config(['crm.celia.catalog_max_chars.preview' => 300]);
    acatChat($token)->set('draft', ACAT_REAL_196)->call('send');
    $reply = acatLastReply();

    expect($reply->content)->toBe('Tenemos 20 Programas Ejecutivos disponibles. Puedes verlos todos aquí: '.ACAT_PE_LINK.' Dime cuál te interesa y te doy los detalles.')
        ->and(mb_strlen($reply->content))->toBeLessThanOrEqual(300)
        ->and($reply->meta['catalog'])->toMatchArray(['mode' => 'compact', 'count' => 20, 'limit' => 300]);
});

it('una pregunta de catálogo de una línea que el asesor no ofrece la responde la IA; las demás preguntas llevan el índice del catálogo', function () {
    [, , $token, $fake] = acatCtx();
    $chat = acatChat($token);

    $chat->set('draft', '¿Qué maestrías tienen disponibles?')->call('send');
    expect($fake->calls)->toHaveCount(1);

    $chat->set('draft', '¿Las clases son en vivo?')->call('send');
    expect(end($fake->calls)['messages'][0]['content'])
        ->toContain('CATÁLOGO ACTIVO DE ESTE ASESOR (programas por línea):')->toContain('Programas Ejecutivos: 20')->toContain('Diplomas Avanzados: 3')->toContain('Micro MBA: 1')
        ->toContain('nunca afirmes que una línea tiene un solo programa');
});

it('la respuesta aprobada de un listado (corrección 5) NO reemplaza el catálogo: gana la base de datos', function () {
    [, $bot, $token, $fake] = acatCtx();
    AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => 'Que programas ejecutivos tiene disponibles',
        'topic_line' => 'programas_ejecutivos', 'answer' => 'Tenemos el Programa Ejecutivo en Gestión 1. Más aquí: '.ACAT_PE_LINK, 'active' => true]);

    acatChat($token)->set('draft', ACAT_REAL_196)->call('send');
    $reply = acatLastReply();

    expect($reply->meta['action'])->toBe('catalog')
        ->and($reply->content)->toContain('Programa Ejecutivo en Gestión 20')
        ->and($reply->meta)->not->toHaveKey('correction')
        ->and($fake->calls)->toHaveCount(0);
});

it('las respuestas aprobadas (no de catálogo) salen literalmente y sin IA, con faltas de ortografía; las preguntas distintas no las activan', function () {
    [, $bot, $token, $fake] = acatCtx();
    // Las cuatro correcciones de producción que no son listados (preguntas tal cual; respuestas de prueba).
    $make = fn (string $q, string $line, string $a) => AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => $q, 'topic_line' => $line, 'answer' => $a, 'active' => true]);
    $c4 = $make('CUal e sel costo del Micro MBA', 'micro_mba', 'El Micro MBA cuesta 100 USD (respuesta de prueba).');
    $c3 = $make('Hay clases sincrónicas?', 'programas_ejecutivos', 'Las clases de los programas ejecutivos son asincrónicas (respuesta de prueba).');
    $c2 = $make('El titulo que entrega el Micro MBA es oficial', 'micro_mba', 'El título del Micro MBA es propio de la institución (respuesta de prueba).');
    $c1 = $make('Y en general, para el Micro MBA, que requisitos debo cumplir', 'micro_mba', 'Para el Micro MBA necesitas experiencia profesional (respuesta de prueba).');

    $cases = [
        ['¿Cuál es el costo del Micro MBA?', $c4],
        ['Hay clases sincronicas en los programas ejecutivos?', $c3],
        ['el título del micro mba es oficial?', $c2],
        ['Qué requisito debo cumplir para el Micro MBA', $c1],          // singular / plural
    ];
    foreach ($cases as [$question, $correction]) {
        $chat = acatChat($token);
        $chat->set('draft', $question)->call('send');
        $reply = acatLastReply();
        expect($reply->content)->toBe($correction->answer)
            ->and($reply->meta['action'])->toBe('approved')
            ->and($reply->meta['correction_check'])->toMatchArray(['id' => $correction->id, 'applied' => true, 'verbatim' => true]);
        $chat->assertSee('Respuesta aprobada (literal)');
    }
    expect($fake->calls)->toHaveCount(0);

    // Preguntas parecidas pero DISTINTAS: responde la IA y la traza dice que no se aplicó.
    foreach (['¿Cuánto dura el Micro MBA?', '¿Las clases quedan grabadas en el Micro MBA?', '¿Qué requisitos tiene el Diploma Avanzado en Finanzas?'] as $question) {
        $before = count($fake->calls);
        acatChat($token)->set('draft', $question)->call('send');
        expect(count($fake->calls))->toBe($before + 1)
            ->and(acatLastReply()->meta['action'] ?? null)->not->toBe('approved');
    }
});

it('tolera faltas de ortografía y singular/plural al comparar preguntas', function () {
    [, $bot] = acatCtx();
    AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => '¿Cuáles son los requisitos de admisión del Micro MBA?', 'topic_line' => 'micro_mba', 'answer' => 'Requisitos de prueba.', 'active' => true]);
    $svc = app(AdvisorCorrections::class);

    expect($svc->match($bot->id, 'cuales son los requsitos de admision del micro mba', [], 'es')['applied'])->toBeTrue()
        ->and($svc->match($bot->id, 'que requisito de admision pide el micro mba', [], 'es')['applied'])->toBeTrue()
        ->and($svc->match($bot->id, 'cuanto cuesta el micro mba', [], 'es')['applied'])->toBeFalse();
});

it('varios mensajes agrupados en un turno: cada fragmento se compara por separado', function () {
    [, $bot] = acatCtx();
    AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => 'CUal e sel costo del Micro MBA', 'topic_line' => 'micro_mba', 'answer' => 'Costo de prueba.', 'active' => true]);

    $match = app(AdvisorCorrections::class)->match($bot->id, "Hola buenas tardes\nsoy de Lima\nCual es el costo del Micro MBA", [], 'es');

    expect($match)->toMatchArray(['applied' => true, 'fragment' => 3, 'language' => 'es']);
});

it('idioma de la corrección: sale de su propio texto; la conversación histórica solo desempata', function () {
    [$inst, $bot] = acatCtx();
    $svc = app(AdvisorCorrections::class);
    $make = fn (string $q, string $a, ?int $feedback = null) => AdvisorCorrection::query()->create(['institution_id' => $inst->id, 'bot_id' => $bot->id, 'question' => $q, 'topic_line' => null, 'answer' => $a, 'active' => true, 'feedback_id' => $feedback]);

    // Sin cadena valoración → mensaje → conversación (no existe): el texto basta.
    expect($svc->languageOf($make('¿Cuál es el costo del programa?', 'Son 100 USD en tres pagos.')))->toBe('es')
        ->and($svc->languageOf($make('What is the cost of the program?', 'It is 100 USD in three payments.')))->toBe('en')
        ->and($svc->languageOf($make('Micro MBA?', '100 USD.')))->toBeNull();

    // Texto sin señal: desempata la conversación de «Probar asesor» donde se aprobó.
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'language' => 'en']);
    $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender_type' => 'celia', 'content' => 'x', 'message_type' => 'text']);
    $feedback = AdvisorFeedback::query()->create(['institution_id' => $inst->id, 'bot_id' => $bot->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id, 'rating' => 'needs_improvement']);
    expect($svc->languageOf($make('Micro MBA?', '100 USD.', $feedback->id)))->toBe('en')
        ->and(TextLanguage::detect('¿Qué programas tienen?'))->toBe('es')
        ->and(TextLanguage::detect('Which programs do you have?'))->toBe('en');
});

it('si el idioma de la respuesta aprobada no es el de la conversación, la IA la sigue como bloque prioritario (no literal)', function () {
    [, $bot, $token, $fake] = acatCtx();
    AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => '¿Cuál es el costo del Micro MBA?', 'topic_line' => 'micro_mba',
        'answer' => 'The cost of the Micro MBA is 100 USD and it can be paid in three payments with the card of your choice.', 'active' => true]);

    acatChat($token)->set('draft', 'Cual es el costo del Micro MBA')->call('send');
    $reply = acatLastReply();

    expect($fake->calls)->toHaveCount(1)
        ->and(end($fake->calls)['messages'][0]['content'])->toStartWith('RESPUESTA APROBADA POR EL EQUIPO')
        ->and($reply->meta['correction_check'])->toMatchArray(['applied' => true, 'language' => 'en', 'verbatim' => false]);
});

it('fuera de la prueba la traza es compacta: identificadores, códigos, títulos y puntuaciones, sin el contenido', function () {
    [, $bot] = acatCtx();
    AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => '¿Cuál es el costo del Micro MBA?', 'topic_line' => 'micro_mba', 'answer' => 'Texto aprobado confidencial de prueba.', 'active' => true]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel' => 'whatsapp', 'mode' => 'celia', 'language' => 'es']);

    app(CeliaService::class)->handle($conversation, null, 'Cual es el costo del Micro MBA', 'es');
    $meta = acatLastReply()->meta;

    expect($meta['correction_check'])->toHaveKeys(['id', 'score', 'threshold', 'applied', 'line', 'fragment', 'language'])
        ->and($meta['correction_check'])->not->toHaveKey('question')
        ->and((string) json_encode($meta, JSON_UNESCAPED_UNICODE))->not->toContain('costo del Micro MBA')
        ->and((string) json_encode($meta, JSON_UNESCAPED_UNICODE))->not->toContain('Texto aprobado')->and($meta)->not->toHaveKey('answer');
});

it('aislamiento: el catálogo y su enlace no salen del asesor ni de la institución', function () {
    [$instA, $botA] = acatCtx();
    $catalog = app(AdvisorCatalog::class);
    expect($catalog->answer($botA->id, ACAT_REAL_196, 'es', 'preview')['trace']['count'])->toBe(20);

    // Otro asesor de la MISMA institución sin programas asignados: no hay catálogo (responde la IA).
    $other = Bot::factory()->withOwnIdentity(['assistant_name' => 'Otro', 'instructions' => 'x', 'knowledge_retrieval' => Bot::RETRIEVAL_PRECISE])->create();
    expect($catalog->answer($other->id, ACAT_REAL_196, 'es', 'preview'))->toBeNull();

    // Desde otra institución, ni los programas ni las fuentes de A existen.
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($instB->id);
    expect($catalog->answer($botA->id, ACAT_REAL_196, 'es', 'preview'))->toBeNull();
});

it('«Enlace oficial» de la fuente: se edita desde la biblioteca, solo https, y la re-sincronización no lo pisa', function () {
    [$inst] = acatCtx();
    $source = KnowledgeSource::query()->where('code', 'DA-INFO')->firstOrFail();
    $this->actingAs(User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']));

    $lib = Livewire::test(Library::class)->call('view', $source->id)->assertSee('Enlace oficial');
    $lib->set('viewingUrl', 'http://example.test/diplomas/')->call('saveUrl')->assertHasErrors('viewingUrl');
    $lib->set('viewingUrl', 'javascript:alert(1)')->call('saveUrl')->assertHasErrors('viewingUrl');
    expect($source->fresh()->url)->toBeNull();

    $lib->set('viewingUrl', ' https://example.test/es/estudios/diplomas-avanzados/ ')->call('saveUrl')->assertHasNoErrors();
    expect($source->fresh()->url)->toBe('https://example.test/es/estudios/diplomas-avanzados/');

    // Desde ahora la lista de Diplomas Avanzados lleva su enlace.
    $answer = app(AdvisorCatalog::class)->answer($source->bots()->first()->id, '¿Qué diplomas avanzados tienen disponibles?', 'es', 'preview');
    expect($answer['reply'])->toContain('https://example.test/es/estudios/diplomas-avanzados/')->and($answer['trace']['link_source'])->toBe('DA-INFO');

    // Vacío = quitarlo.
    $lib->set('viewingUrl', '')->call('saveUrl')->assertHasNoErrors();
    expect($source->fresh()->url)->toBeNull();
});

it('solo quien puede editar la fuente guarda el enlace oficial', function () {
    [$inst] = acatCtx();
    $source = KnowledgeSource::query()->where('code', 'DA-INFO')->firstOrFail();
    $this->actingAs(User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']));

    Livewire::test(Library::class)->assertForbidden();
    expect($source->fresh()->url)->toBeNull();
});
