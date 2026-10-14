<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Ai\Models\AdvisorCorrection;
use Modules\Ai\Models\AdvisorMessageReceipt;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Jobs\RespondWithAdvisor;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialAutomationService;
use Modules\Social\Services\SocialOutboundService;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * WhatsApp y Messenger con el asesor en búsqueda precisa (mismo núcleo que «Probar asesor»):
 *  - preguntas de CATÁLOGO respondidas desde los datos (programas activos asignados + enlace
 *    oficial), sin IA. Los tres mensajes reales se OBSERVARON en Messenger (conversación social 2);
 *    aquí se reproducen como regresión en WhatsApp y Messenger;
 *  - «unresolved»: el primero responde con honestidad y el asesor SIGUE; el segundo consecutivo
 *    (misma conversación, ≤ 30 min, sin respuesta resuelta/catálogo/aprobada, persona ni
 *    reactivación en medio) envía el mensaje de transferencia y pasa a «Esperando a una persona».
 *
 * @return array{0: Institution, 1: Bot, 2: FakeAiChatClient}
 */
function scatCtx(array $channelAttrs = []): array
{
    config(['social.app_secret' => 'scat_secret', 'social.secrets.instagram' => 'scat_secret', 'social.advisor.autoreply_enabled' => true]);
    Http::fake([
        'graph.facebook.com/*/me/messages' => fn () => Http::response(['message_id' => 'm_bot_'.Str::random(10)]),
        'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.BOT'.Str::random(10)]]]),
        '*' => Http::response([]),
    ]);

    $inst = Institution::factory()->create();
    $bot = app(CurrentInstitution::class)->runFor($inst->id, function () use ($channelAttrs) {
        $bot = Bot::factory()->withOwnIdentity(['assistant_name' => 'Sophia', 'instructions' => 'Asesora de líneas ejecutivas.', 'knowledge_retrieval' => Bot::RETRIEVAL_PRECISE, 'status' => 'active', 'typing_delay' => 0, 'default_language' => 'es'])->create();
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
        foreach (['whatsapp' => 'demo_wa_phone', 'messenger' => 'demo_fb_page'] as $provider => $ext) {
            SocialChannel::factory()->create($channelAttrs + ['advisor_enabled' => true, 'advisor_handoff_message' => 'Te paso con una persona del equipo.', 'provider' => $provider, 'external_id' => $ext, 'advisor_bot_id' => $bot->id]);
        }

        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $ids[] = Program::factory()->create(['code' => sprintf('PE-%02d', $i), 'name_es' => "Programa Ejecutivo en Gestión {$i}", 'line' => 'programas_ejecutivos', 'display_order' => $i])->id;
        }
        foreach (['Finanzas', 'Marketing', 'Liderazgo'] as $i => $n) {
            $ids[] = Program::factory()->create(['code' => 'DA-0'.$i, 'name_es' => "Diploma Avanzado en {$n}", 'line' => 'diplomas_avanzados'])->id;
        }
        $ids[] = Program::factory()->create(['code' => 'PE-OFF', 'name_es' => 'Programa Ejecutivo Retirado', 'line' => 'programas_ejecutivos', 'status' => 'inactive'])->id;
        $bot->programs()->sync($ids);
        foreach ([['PE-FAQ', 'programas_ejecutivos', 'https://example.test/es/estudios/programas-ejecutivos/'], ['DA-INFO', 'diplomas_avanzados', null], ['MMBA-INFO', 'micro_mba', null]] as [$code, $line, $url]) {
            KnowledgeSource::factory()->create(['bot_id' => null, 'code' => $code, 'category' => $line, 'type' => 'base_conocimiento', 'priority' => 6, 'status' => 'active', 'url' => $url,
                'content_es' => "## Información general {$code}\nModalidad online.\n\n## Costo {$code}\nSe paga en cuotas."])->bots()->attach($bot->id, ['is_active' => true]);
        }

        return $bot;
    });

    $fake = new FakeAiChatClient('{"reply": "No tengo ese dato en mis fuentes; ¿te ayudo con otra consulta?", "action": "unresolved"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, $fake];
}

/** Mensaje entrante de la persona (fixture real del proveedor con otro texto e id). */
function scatPost(string $provider, string $text): void
{
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/'.$provider.'.json');
    $raw = str_replace(['1757001000000', '"1757001000"'], [(string) now()->getTimestampMs(), '"'.now()->getTimestamp().'"'], $raw);
    $raw = str_replace(
        ['wamid.HBgTEST00000001', 'm_MSGR00000001', 'Hola! Vi el diploma en liderazgo en Facebook, ¿cuándo inicia el próximo grupo?', 'Hola, ¿la microcredencial en finanzas tiene cupo disponible?'],
        ['wamid.SCAT'.Str::random(12), 'm_SCAT'.Str::random(12), $text, $text],
        $raw,
    );
    test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'scat_secret'),
    ], $raw)->assertOk();
}

/** @return \Illuminate\Support\Collection<int, SocialMessage> */
function scatBotMessages(Institution $inst, string $provider): \Illuminate\Support\Collection
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()
        ->whereHas('conversation', fn ($q) => $q->where('provider', $provider))
        ->where('sender_type', 'bot')->orderBy('id')->get());
}

function scatConversation(Institution $inst, string $provider): SocialConversation
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialConversation::query()->with('channel')->where('provider', $provider)->firstOrFail());
}

dataset('scat_canales', ['whatsapp', 'messenger']);

it('casos reales (observados en Messenger) reproducidos como regresión: resumen, los 20 ejecutivos y el enlace, sin IA', function (string $provider) {
    [$inst, , $fake] = scatCtx();

    scatPost($provider, 'Que programas tiene disponibles');
    scatPost($provider, 'Que programas ejecutivos tuenen disponible');
    scatPost($provider, 'Estas seguro, puedes darme el link de donde ver los programas ejecutivos disponibles');
    [$summary, $list, $again] = scatBotMessages($inst, $provider)->all();

    expect($summary->body)->toContain('Programas Ejecutivos: 20 programas — https://example.test/es/estudios/programas-ejecutivos/')
        ->toContain('Diplomas Avanzados: 3 programas')->toContain('¿Cuál te gustaría consultar?')
        ->and($list->body)->toStartWith('Estos son los 20 Programas Ejecutivos disponibles:')
        ->toContain('• Programa Ejecutivo en Gestión 20')->not->toContain('Retirado')->not->toContain('único')
        ->toContain('Puedes verlos todos aquí: https://example.test/es/estudios/programas-ejecutivos/')
        ->and($again->body)->toBe($list->body)
        ->and($fake->calls)->toHaveCount(0)
        ->and(scatConversation($inst, $provider)->automation_state)->toBe('bot');

    // Traza compacta en el canal: decisión, códigos y cantidades; nunca el contenido de las fuentes.
    expect($list->ai_meta)->toMatchArray(['kind' => 'answered', 'intent' => 'catalog', 'used_ai' => false, 'knowledge_sources' => ['PE-FAQ']])
        ->and($list->ai_meta['catalog'])->toMatchArray(['mode' => 'list', 'line' => 'programas_ejecutivos', 'count' => 20, 'link_source' => 'PE-FAQ'])
        ->and($summary->ai_meta['catalog'])->toMatchArray(['mode' => 'summary', 'link_missing' => ['diplomas_avanzados']])
        ->and((string) json_encode($list->ai_meta, JSON_UNESCAPED_UNICODE))->not->toContain('Modalidad online')->not->toContain('Gestión 1');
})->with('scat_canales');

it('una respuesta aprobada sale literal y sin IA en el canal, con su traza compacta', function (string $provider) {
    [$inst, $bot, $fake] = scatCtx();
    $c = app(CurrentInstitution::class)->runFor($inst->id, fn () => AdvisorCorrection::query()->create(['institution_id' => $inst->id, 'bot_id' => $bot->id,
        'question' => 'CUal e sel costo del Micro MBA', 'topic_line' => 'micro_mba', 'answer' => 'El Micro MBA cuesta 100 USD (respuesta de prueba).', 'active' => true]));

    scatPost($provider, '¿Cuál es el costo del Micro MBA?');
    $reply = scatBotMessages($inst, $provider)->sole();

    expect($reply->body)->toBe($c->answer)
        ->and($fake->calls)->toHaveCount(0)
        ->and($reply->ai_meta)->toMatchArray(['intent' => 'approved', 'used_ai' => false])
        ->and($reply->ai_meta['correction'])->toMatchArray(['id' => $c->id, 'applied' => true, 'verbatim' => true])
        ->and((string) json_encode($reply->ai_meta, JSON_UNESCAPED_UNICODE))->not->toContain('100 USD')->not->toContain('costo del Micro');
})->with('scat_canales');

it('primer «unresolved»: responde con honestidad y el asesor SIGUE atendiendo, con la nota interna en la bandeja', function () {
    [$inst] = scatCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    $conversation = scatConversation($inst, 'messenger');

    expect(scatBotMessages($inst, 'messenger')->sole()->ai_meta['kind'])->toBe('unresolved')
        ->and($conversation->automation_state)->toBe('bot');

    app(CurrentInstitution::class)->set($inst->id);
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)
        ->assertSeeHtml('data-testid="unresolved-note"')->assertSee('Consulta fuera de sus fuentes');
});

it('segundo «unresolved» consecutivo (≤ 30 min): envía la respuesta y el mensaje de transferencia, y solo entonces espera a una persona', function () {
    [$inst] = scatCtx();

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    $this->travel(29)->minutes();
    scatPost('messenger', '¿Y descuentos para grupos?');
    $messages = scatBotMessages($inst, 'messenger');
    $conversation = scatConversation($inst, 'messenger');

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->ai_meta['kind'])->toBe('unresolved')
        ->and($messages[1]->ai_meta['kind'])->toBe('unresolved')
        ->and($messages[2]->body)->toBe('Te paso con una persona del equipo.')   // nunca en silencio
        ->and($conversation->automation_state)->toBe('waiting_human')
        ->and($conversation->automation_reason)->toBe('unresolved');
});

it('el contador se reinicia tras más de 30 minutos sin actividad', function () {
    [$inst] = scatCtx();

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    $this->travel(31)->minutes();
    scatPost('messenger', '¿Y descuentos para grupos?');

    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(2)
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');
});

it('el contador se reinicia con una respuesta resuelta, de catálogo o aprobada en medio', function (string $between) {
    [$inst, $bot, $fake] = scatCtx();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => AdvisorCorrection::query()->create(['institution_id' => $inst->id, 'bot_id' => $bot->id,
        'question' => 'Hay clases sincrónicas?', 'topic_line' => null, 'answer' => 'Las clases son asincrónicas (respuesta de prueba).', 'active' => true]));
    $unresolved = '{"reply": "No tengo ese dato en mis fuentes; ¿te ayudo con otra consulta?", "action": "unresolved"}';

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    if ($between === 'resuelta') {
        $fake->willReturn('{"reply": "Sí, la modalidad es online.", "action": "answer"}');
        scatPost('messenger', '¿La modalidad es online?');
        $fake->willReturn($unresolved);
    } else {
        scatPost('messenger', $between === 'catálogo' ? '¿Qué diplomas avanzados tienen disponibles?' : '¿Hay clases sincrónicas?');
    }
    scatPost('messenger', '¿Y descuentos para grupos?');

    $kinds = scatBotMessages($inst, 'messenger')->map(fn (SocialMessage $m) => $m->ai_meta['kind'] ?? null)->all();
    expect($kinds)->toBe(['unresolved', 'answered', 'unresolved'])
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');
})->with(['resuelta', 'catálogo', 'aprobada']);

it('el contador se reinicia con la intervención de una persona y la reactivación manual', function () {
    [$inst] = scatCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    $conversation = scatConversation($inst, 'messenger');
    app(CurrentInstitution::class)->runFor($inst->id, function () use ($conversation, $agent): void {
        app(SocialOutboundService::class)->send($conversation->fresh(), 'Hola, lo reviso y te escribo.', $agent);   // la persona toma la conversación
        app(SocialAutomationService::class)->returnToAdvisor($conversation->fresh(), $agent);                       // y reactiva el asesor
    });
    $this->travel(1)->minutes();
    scatPost('messenger', '¿Y descuentos para grupos?');

    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(2)
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');

    // Solo con la reactivación (sin mensaje de la persona) también se reinicia.
    $this->travel(1)->minutes();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor(scatConversation($inst, 'messenger')));
    $this->travel(1)->minutes();
    scatPost('messenger', '¿Tienen becas?');

    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(3)
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');
});

it('dos «unresolved» en conversaciones DISTINTAS no se suman', function () {
    [$inst] = scatCtx();

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    scatPost('whatsapp', '¿Y descuentos para grupos?');

    expect(scatConversation($inst, 'messenger')->automation_state)->toBe('bot')
        ->and(scatConversation($inst, 'whatsapp')->automation_state)->toBe('bot');
});

it('las conversaciones que ya estaban «Esperando a una persona» quedan intactas', function () {
    [$inst, , $fake] = scatCtx();
    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    $conversation = scatConversation($inst, 'messenger');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->waitForPerson($conversation, 'unresolved'));
    $before = scatConversation($inst, 'messenger')->only(['automation_state', 'automation_reason', 'automation_changed_at', 'assigned_to']);
    $calls = count($fake->calls);

    scatPost('messenger', 'Que programas tiene disponibles');   // ni siquiera el catálogo la reactiva

    expect(scatConversation($inst, 'messenger')->only(['automation_state', 'automation_reason', 'automation_changed_at', 'assigned_to']))->toEqual($before)
        ->and(scatBotMessages($inst, 'messenger'))->toHaveCount(1)
        ->and($fake->calls)->toHaveCount($calls);
});

// --- Reactivación manual en el MISMO segundo que un «unresolved» -------------------------------
// Los timestamps de la base tienen precisión de segundos: el orden se toma de la traza
// (ai_meta.streak_reset), no de comparar horas.

/** Primer «unresolved» y reactivación manual dentro del mismo segundo (tiempo congelado). */
function scatUnresolvedThenReactivate(Institution $inst): SocialConversation
{
    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor(scatConversation($inst, 'messenger')));

    return scatConversation($inst, 'messenger');
}

it('1 · primer «unresolved» y reactivación manual en el mismo segundo: la cuenta queda reiniciada', function () {
    [$inst] = scatCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $this->freezeSecond();

    $conversation = scatUnresolvedThenReactivate($inst);
    $first = scatBotMessages($inst, 'messenger')->sole();

    expect($conversation->automation_changed_at->equalTo($first->created_at))->toBeTrue()     // mismo segundo
        ->and($first->ai_meta)->toMatchArray(['kind' => 'unresolved', 'streak_reset' => 'reactivated'])
        ->and($conversation->automation_state)->toBe('bot');

    // La nota interna ya no anuncia una transferencia: la cuenta empezó de nuevo.
    app(CurrentInstitution::class)->set($inst->id);
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', $conversation->id)->assertDontSeeHtml('data-testid="unresolved-note"');
});

it('2 · nuevo «unresolved» tras esa reactivación (mismo segundo): es el primero, responde con honestidad y sigue en «bot»', function () {
    [$inst] = scatCtx();
    $this->freezeSecond();

    scatUnresolvedThenReactivate($inst);
    scatPost('messenger', '¿Y descuentos para grupos?');
    $messages = scatBotMessages($inst, 'messenger');

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('body')->all())->not->toContain('Te paso con una persona del equipo.')
        ->and($messages[1]->ai_meta['kind'])->toBe('unresolved')
        ->and($messages[1]->ai_meta)->not->toHaveKey('streak_reset')
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');
});

it('3 · dos «unresolved» seguidos sin reactivación (incluso en el mismo segundo): envía la transferencia y pasa a «Esperando a una persona»', function () {
    [$inst] = scatCtx();
    $this->freezeSecond();

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    scatPost('messenger', '¿Y descuentos para grupos?');
    $messages = scatBotMessages($inst, 'messenger');
    $conversation = scatConversation($inst, 'messenger');

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->ai_meta['kind'])->toBe('unresolved')
        ->and($messages[1]->ai_meta['kind'])->toBe('unresolved')
        ->and($messages[2]->body)->toBe('Te paso con una persona del equipo.')
        ->and($conversation->automation_state)->toBe('waiting_human')
        ->and($conversation->automation_reason)->toBe('unresolved');
});

it('4 · los mensajes anteriores a la reactivación siguen sin respuesta (ni se agrupan con los nuevos)', function () {
    [$inst, , $fake] = scatCtx();
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->takeOver(scatConversation($inst, 'messenger'), $agent));
    $this->travel(10)->seconds();
    scatPost('messenger', 'Mensaje mientras atendía una persona');
    $calls = count($fake->calls);
    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(1);

    $this->travel(1)->minutes();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor(scatConversation($inst, 'messenger'), $agent));
    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(1)->and($fake->calls)->toHaveCount($calls);   // reactivar no contesta lo antiguo

    $this->travel(1)->minutes();
    scatPost('messenger', '¿Y descuentos para grupos?');

    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(2)
        ->and((string) json_encode(end($fake->calls), JSON_UNESCAPED_UNICODE))->toContain('descuentos para grupos')->not->toContain('mientras atendía una persona')
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');
});

it('5 · reactivación (mismo segundo), respuesta correcta y nuevo «unresolved»: la cuenta empieza otra vez en uno', function () {
    [$inst, , $fake] = scatCtx();
    $this->freezeSecond();
    $unresolved = '{"reply": "No tengo ese dato en mis fuentes; ¿te ayudo con otra consulta?", "action": "unresolved"}';

    scatUnresolvedThenReactivate($inst);
    $fake->willReturn('{"reply": "Sí, la modalidad es online.", "action": "answer"}');
    scatPost('messenger', '¿La modalidad es online?');
    $fake->willReturn($unresolved);
    scatPost('messenger', '¿Y descuentos para grupos?');

    expect(scatBotMessages($inst, 'messenger')->map(fn (SocialMessage $m) => $m->ai_meta['kind'] ?? null)->all())->toBe(['unresolved', 'answered', 'unresolved'])
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot');

    // Ese fue el PRIMERO de una nueva cuenta: el siguiente seguido sí transfiere.
    scatPost('messenger', '¿Tienen becas?');
    expect(scatBotMessages($inst, 'messenger')->last()->body)->toBe('Te paso con una persona del equipo.')
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('waiting_human');
});

// --- Corte ATÓMICO de la reactivación (todo en el MISMO segundo) --------------------------------
// Reactivar marca, en una transacción con la conversación bloqueada, todos los mensajes ya
// recibidos con el recibo persistente de idempotencia («before_reactivation»); solo después pasa a
// «bot». Los jobs pendientes de esos mensajes quedan omitidos y nunca se agrupan con los nuevos.

function scatQueued(): int
{
    return DB::table('jobs')->where('queue', AdvisorDispatcher::queue())->count();
}

function scatWorker(): void
{
    Artisan::call('social:advisor-worker', ['--stop-when-empty' => true, '--memory' => 0]);
}

function scatInbound(Institution $inst, string $body): SocialMessage
{
    return app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('direction', 'inbound')->where('body', $body)->sole());
}

it('reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia', function (string $provider, string $jobA) {
    [$inst, , $fake] = scatCtx();
    config(['queue.default' => 'database']);
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $this->freezeSecond();

    // Conversación atendida por el asesor (primer «unresolved») y luego tomada por una persona.
    scatPost($provider, '¿Tienen convenio con mi empresa?');
    scatWorker();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->takeOver(scatConversation($inst, $provider), $agent));

    // 1. Llega A mientras atiende una persona (su job queda pendiente o, según el caso, corre ya).
    scatPost($provider, 'Mensaje A antes de reactivar');
    if ($jobA === 'mientras_persona') {
        scatWorker();
    }
    // 2. Se reactiva Sophia (mismo segundo).
    $ok = app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor(scatConversation($inst, $provider), $agent));
    if ($jobA === 'antes_de_B') {
        scatWorker();
    }
    // 3. Llega B después de la reactivación (mismo segundo).
    scatPost($provider, 'Mensaje B después de reactivar');
    // 4. El job pendiente de A (si quedaba) corre ahora, junto al de B.
    scatWorker();

    $a = scatInbound($inst, 'Mensaje A antes de reactivar');
    $b = scatInbound($inst, 'Mensaje B después de reactivar');
    $replies = scatBotMessages($inst, $provider);
    $aiCall = (string) json_encode(end($fake->calls), JSON_UNESCAPED_UNICODE);

    // 5. Solo responde a B (una vez) y sin mezclar el texto de A.
    expect($ok)->toBeTrue()
        ->and($replies)->toHaveCount(2)
        ->and($replies->where('in_reply_to_id', $a->id))->toHaveCount(0)
        ->and($replies->where('in_reply_to_id', $b->id))->toHaveCount(1)
        ->and($fake->calls)->toHaveCount(2)
        ->and($aiCall)->toContain('Mensaje B después de reactivar')->not->toContain('Mensaje A antes de reactivar')
        ->and(app(CurrentInstitution::class)->runFor($inst->id, fn () => AdvisorMessageReceipt::query()->where('external_message_id', $a->external_message_id)->value('status')))->toBe('before_reactivation');

    // 8. La racha «unresolved» también se reinició: B es el primero de una nueva cuenta.
    expect($replies[1]->ai_meta['kind'])->toBe('unresolved')
        ->and($replies[0]->ai_meta['streak_reset'] ?? null)->toBe('taken_over')
        ->and(scatConversation($inst, $provider)->automation_state)->toBe('bot');

    // 6. Reiniciar o repetir el worker (y volver a encolar A y B) no genera respuestas nuevas.
    scatWorker();
    RespondWithAdvisor::dispatch($a->id, $inst->id);
    RespondWithAdvisor::dispatch($b->id, $inst->id);
    scatWorker();
    expect(scatBotMessages($inst, $provider))->toHaveCount(2)->and($fake->calls)->toHaveCount(2)->and(scatQueued())->toBe(0);

    // 7. Un mensaje normal posterior sigue funcionando (y es el segundo «unresolved» seguido: transfiere).
    scatPost($provider, '¿Tienen becas?');
    scatWorker();
    expect(scatBotMessages($inst, $provider)->last()->body)->toBe('Te paso con una persona del equipo.')
        ->and($fake->calls)->toHaveCount(3)
        ->and(scatConversation($inst, $provider)->automation_state)->toBe('waiting_human');
})->with(['whatsapp', 'messenger'])->with([
    'el job de A corre mientras atiende la persona' => 'mientras_persona',
    'el job de A corre tras reactivar y antes de B' => 'antes_de_B',
    'el job de A corre después de llegar B' => 'despues_de_B',
]);

it('un mensaje recibido normalmente después de la reactivación se responde (Messenger y WhatsApp)', function (string $provider) {
    [$inst, , $fake] = scatCtx();
    $fake->willReturn('{"reply": "Sí, la modalidad es online.", "action": "answer"}');
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    scatPost($provider, 'Hola');
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->takeOver(scatConversation($inst, $provider), $agent));
    $this->travel(5)->minutes();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->returnToAdvisor(scatConversation($inst, $provider), $agent));
    $this->travel(1)->minutes();
    scatPost($provider, '¿La modalidad es online?');

    expect(scatBotMessages($inst, $provider))->toHaveCount(2)
        ->and(scatBotMessages($inst, $provider)->last()->body)->toBe('Sí, la modalidad es online.')
        ->and(scatConversation($inst, $provider)->automation_state)->toBe('bot');
})->with(['whatsapp', 'messenger']);

it('concurrencia: si un worker tiene el turno (lease), la reactivación no se fuerza ni toca nada; al liberarlo, reactiva', function () {
    [$inst] = scatCtx();
    config(['queue.default' => 'database']);
    $agent = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $this->freezeSecond();
    scatPost('messenger', '¿Tienen convenio con mi empresa?');
    scatWorker();
    app(CurrentInstitution::class)->runFor($inst->id, fn () => app(SocialAutomationService::class)->takeOver(scatConversation($inst, 'messenger'), $agent));
    scatPost('messenger', 'Mensaje A antes de reactivar');
    $automation = app(SocialAutomationService::class);
    $a = scatInbound($inst, 'Mensaje A antes de reactivar');
    app(CurrentInstitution::class)->set($inst->id);

    // Un worker reclamó la conversación (turno persistente vigente).
    expect($automation->claim(scatConversation($inst, 'messenger')))->toBeTrue();
    $fields = ['automation_state', 'automation_reason', 'automation_changed_at', 'advisor_lease_until', 'assigned_to'];
    $before = scatConversation($inst, 'messenger')->only($fields);

    expect($automation->returnToAdvisor(scatConversation($inst, 'messenger'), $agent))->toBeFalse()
        ->and(scatConversation($inst, 'messenger')->only($fields))->toEqual($before)
        ->and(AdvisorMessageReceipt::query()->where('external_message_id', $a->external_message_id)->exists())->toBeFalse();

    // En la bandeja se explica y se puede reintentar.
    Livewire::actingAs($agent)->test(Inbox::class)->call('select', scatConversation($inst, 'messenger')->id)->call('returnToAdvisor')
        ->assertSee('El asesor está terminando un turno anterior');
    expect(scatConversation($inst, 'messenger')->automation_state)->toBe('human');

    // El worker termina y libera su turno: ahora sí reactiva, con el corte.
    $automation->release(scatConversation($inst, 'messenger'));
    expect($automation->returnToAdvisor(scatConversation($inst, 'messenger'), $agent))->toBeTrue()
        ->and(scatConversation($inst, 'messenger')->automation_state)->toBe('bot')
        ->and(AdvisorMessageReceipt::query()->where('external_message_id', $a->external_message_id)->value('status'))->toBe('before_reactivation');

    // La reactivación no toma el turno: un worker que reclame después lo consigue, y el job de A sigue omitido.
    expect($automation->claim(scatConversation($inst, 'messenger')))->toBeTrue();
    $automation->release(scatConversation($inst, 'messenger'));
    scatWorker();
    expect(scatBotMessages($inst, 'messenger'))->toHaveCount(1);
});
