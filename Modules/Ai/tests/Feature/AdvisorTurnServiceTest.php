<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorTurn;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Capa común del asesor por canal (AdvisorTurnService): el mismo asesor y la misma lógica para
 * cualquier canal, con memoria por canal y conversación, idempotencia, traspaso a persona e
 * institución. Los adaptadores sociales aún no la invocan (siguiente etapa).
 *
 * @return array{0: Institution, 1: Bot, 2: FakeAiChatClient}
 */
function atCtx(string $status = 'active'): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->create(['status' => $status, 'assistant_name' => 'Celia']);
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $kb = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'KB-MC-1', 'status' => 'active', 'content_es' => "## Pagos\nSe paga con tarjeta o transferencia."]);
    $bot->knowledgeSources()->attach($kb->id, ['is_active' => true]);

    $fake = new FakeAiChatClient('{"reply": "Puedes pagar con tarjeta.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, $fake];
}

function atTurn(Bot $bot, string $channel, string $thread, string $text, ?string $mid = null, bool $test = false): AdvisorTurn
{
    return new AdvisorTurn(
        institutionId: (int) $bot->institution_id, botId: (int) $bot->id, channel: $channel,
        externalConversationId: $thread, text: $text, externalMessageId: $mid, isTest: $test,
    );
}

it('responde con el asesor y devuelve estado, intención, fuentes y datos seguros para el canal', function () {
    [, $bot] = atCtx();

    $result = app(AdvisorTurnService::class)->process(atTurn($bot, 'instagram', 'ig-thread-1', '¿Cómo pago?', 'mid.1'));

    expect($result->status)->toBe('answered')
        ->and($result->reply)->toBe('Puedes pagar con tarjeta.')
        ->and($result->intent)->toBe('answer')
        ->and($result->handoff)->toBeFalse()
        ->and($result->usedAi)->toBeTrue()
        ->and($result->sources)->toBe(['KB-MC-1'])
        ->and($result->shouldReply())->toBeTrue()
        ->and($result->adapter)->toMatchArray(['channel' => 'instagram', 'language' => 'es'])
        ->and(json_encode($result->adapter))->not->toContain('KB-MC-1');

    $conversation = Conversation::query()->sole();
    expect($conversation->only(['channel', 'external_id', 'is_test', 'mode']))
        ->toBe(['channel' => 'instagram', 'external_id' => 'ig-thread-1', 'is_test' => false, 'mode' => 'celia']);
});

it('separa la memoria por canal y por conversación externa', function () {
    [, $bot, $fake] = atCtx();
    $svc = app(AdvisorTurnService::class);

    $svc->process(atTurn($bot, 'instagram', 'hilo-1', 'Primera de IG'));
    $svc->process(atTurn($bot, 'messenger', 'hilo-1', 'Primera de Messenger'));
    $svc->process(atTurn($bot, 'instagram', 'hilo-1', 'Segunda de IG'));

    expect(Conversation::query()->count())->toBe(2);
    $history = collect($fake->calls[2]['messages'])->pluck('content')->all();
    expect($history)->toContain('Primera de IG')->not->toContain('Primera de Messenger');
});

it('es idempotente por mensaje externo: un reintento no vuelve a llamar a la IA ni duplica nada', function () {
    [, $bot, $fake] = atCtx();
    $svc = app(AdvisorTurnService::class);

    $first = $svc->process(atTurn($bot, 'whatsapp', '+34600000000', 'Hola', 'wamid.ABC'));
    $retry = $svc->process(atTurn($bot, 'whatsapp', '+34600000000', 'Hola', 'wamid.ABC'));

    expect($fake->calls)->toHaveCount(1)
        ->and($retry->status)->toBe('duplicate')
        ->and($retry->reply)->toBe($first->reply)
        ->and($retry->replyMessageId)->toBe($first->replyMessageId)
        ->and(Message::query()->count())->toBe(2); // usuario + asesor, una sola vez
});

it('traspasada a una persona, el bot no responde en paralelo; al devolverla vuelve a responder', function () {
    [, $bot, $fake] = atCtx();
    $svc = app(AdvisorTurnService::class);

    $svc->process(atTurn($bot, 'messenger', 'm-1', 'Hola'));
    $conversation = Conversation::query()->sole();
    $svc->handOff($conversation);

    $result = $svc->process(atTurn($bot, 'messenger', 'm-1', '¿Sigues ahí?', 'mid.2'));
    expect($result->status)->toBe('human_active')->and($result->handoff)->toBeTrue()
        ->and($result->reply)->toBeNull()->and($result->shouldReply())->toBeFalse()
        ->and($fake->calls)->toHaveCount(1)
        ->and(Message::query()->where('external_id', 'mid.2')->value('sender_type'))->toBe('user'); // queda registrado

    $svc->releaseToAdvisor($conversation->fresh());
    expect($svc->process(atTurn($bot, 'messenger', 'm-1', 'Otra'))->status)->toBe('answered');
});

it('respeta la institución, el estado del asesor y la coherencia canal ↔ prueba', function () {
    [, $bot] = atCtx();
    $svc = app(AdvisorTurnService::class);
    $other = Institution::factory()->create();

    // Otro institution_id: el asesor no existe allí.
    expect(fn () => $svc->process(new AdvisorTurn($other->id, (int) $bot->id, 'instagram', 't', 'Hola')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->process(atTurn($bot, 'telegram', 't', 'Hola')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->process(atTurn($bot, 'preview', 't', 'Hola', null, false)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->process(atTurn($bot, 'instagram', 't', 'Hola', null, true)))->toThrow(InvalidArgumentException::class);
    expect(Conversation::query()->count())->toBe(0);

    // Un asesor INACTIVO solo atiende en pruebas.
    $bot->update(['status' => 'inactive']);
    expect(fn () => $svc->process(atTurn($bot, 'instagram', 't', 'Hola')))->toThrow(InvalidArgumentException::class);
    expect($svc->process(atTurn($bot, 'preview', 'sesion-1', 'Hola', null, true))->status)->toBe('answered');
});

it('sin dato suficiente lo reconoce (unresolved) y nunca envía nada al canal por sí misma', function () {
    [, $bot, $fake] = atCtx();
    Http::fake();
    $fake->willReturn('{"reply": "No tengo ese dato; consulta la ficha.", "action": "unresolved"}');

    $result = app(AdvisorTurnService::class)->process(atTurn($bot, 'instagram', 'ig-2', '¿Cuántos créditos tiene?'));

    expect($result->status)->toBe('unresolved')->and($result->intent)->toBe('unresolved');
    Http::assertNothingSent(); // la capa común no habla con Meta: lo hará el adaptador
});
