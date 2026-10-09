<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Jobs\RespondWithAdvisor;
use Modules\Social\Jobs\SendAdvisorTyping;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\AdvisorTypingIndicator;

/**
 * «Escribiendo» NATIVO de Instagram, Messenger y WhatsApp cuando el asesor inteligente va a
 * contestar: sale justo después de responder al webhook (no ocupa la cola ni el worker), la espera
 * mínima es el retraso del job (nunca un sleep) y un fallo del canal nunca rompe la respuesta.
 *
 * @return array{0: Institution, 1: Bot}
 */
function stypCtx(array $channelAttrs = [], int $typingDelay = 3, bool $typingFails = false): array
{
    config(['social.app_secret' => 'styp_secret', 'social.secrets.instagram' => 'styp_secret', 'social.advisor.autoreply_enabled' => true]);
    Http::fake([
        'graph.facebook.com/*' => function (HttpRequest $r) use ($typingFails) {
            $typing = isset($r->data()['sender_action']) || isset($r->data()['typing_indicator']);
            if ($typing && $typingFails) {
                throw new ConnectionException('timeout');
            }

            return $typing ? Http::response(['success' => true])
                : (str_contains($r->url(), 'me/messages') ? Http::response(['message_id' => 'm_bot_'.uniqid()]) : Http::response(['messages' => [['id' => 'wamid.BOT'.uniqid()]]]));
        },
        '*' => Http::response([]),
    ]);

    $inst = Institution::factory()->create();
    $bot = app(CurrentInstitution::class)->runFor($inst->id, function () use ($channelAttrs, $typingDelay) {
        $bot = Bot::factory()->create(['assistant_name' => 'Sophia', 'typing_delay' => $typingDelay]);
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
        foreach (['whatsapp' => 'demo_wa_phone', 'messenger' => 'demo_fb_page', 'instagram' => 'demo_ig_user'] as $provider => $ext) {
            SocialChannel::factory()->create($channelAttrs + ['provider' => $provider, 'external_id' => $ext, 'advisor_enabled' => true, 'advisor_bot_id' => $bot->id]);
        }

        return $bot;
    });
    app()->instance(AiChatClient::class, new FakeAiChatClient('{"reply": "Sí, hay cupo.", "action": "answer"}'));

    return [$inst, $bot];
}

function stypPost(string $provider): TestResponse
{
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/'.$provider.'.json');
    $raw = str_replace(['1757001000000', '"1757001000"'], [(string) now()->getTimestampMs(), '"'.now()->getTimestamp().'"'], $raw);

    return test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'styp_secret'),
    ], $raw)->assertOk();
}

/** @return list<array<string, mixed>> */
function stypTypingRequests(): array
{
    return collect(Http::recorded())->map(fn ($pair) => ['url' => $pair[0]->url(), 'data' => $pair[0]->data()])
        ->filter(fn (array $r) => isset($r['data']['sender_action']) || isset($r['data']['typing_indicator']))->values()->all();
}

dataset('canales_typing', [
    'messenger' => ['messenger', '/me/messages', ['recipient' => ['id' => 'psid_8887701'], 'sender_action' => 'typing_on']],
    'instagram' => ['instagram', '/me/messages', ['recipient' => ['id' => 'igsid_5552012'], 'sender_action' => 'typing_on']],
    'whatsapp' => ['whatsapp', '/demo_wa_phone/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.HBgTEST00000001', 'typing_indicator' => ['type' => 'text']]],
]);

it('envía la acción nativa de «escribiendo» del canal antes de la respuesta del asesor', function (string $provider, string $endpoint, array $payload) {
    stypCtx();

    stypPost($provider);

    $typing = stypTypingRequests();
    expect($typing)->not->toBeEmpty()
        ->and($typing[0]['url'])->toEndWith($endpoint)
        ->and($typing[0]['data'])->toBe($payload);
    // Primero «escribiendo», después la respuesta; y nada de «escribiendo» una vez contestado.
    $all = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->values();
    $replyAt = $all->search(fn (array $d) => str_contains((string) json_encode($d, JSON_UNESCAPED_UNICODE), 'Sí, hay cupo'));
    expect($replyAt)->toBeInt()
        ->and($all->slice(0, $replyAt)->contains(fn (array $d) => isset($d['sender_action']) || isset($d['typing_indicator'])))->toBeTrue()
        ->and($all->slice($replyAt + 1)->contains(fn (array $d) => isset($d['sender_action']) || isset($d['typing_indicator'])))->toBeFalse();
})->with('canales_typing');

it('no bloquea: el «escribiendo» sale tras responder al webhook (fuera de la cola) y la espera es el retraso del job', function () {
    [$inst] = stypCtx(['advisor_reply_delay' => 0], typingDelay: 5);
    Bus::fake();

    stypPost('messenger');

    Bus::assertDispatchedAfterResponse(SendAdvisorTyping::class, fn (SendAdvisorTyping $job) => $job->institutionId === $inst->id);
    expect(Bus::dispatched(SendAdvisorTyping::class))->toBeEmpty();   // nunca en la cola del worker
    // Espera = la mayor entre la del canal (0) y la del asesor (5): retraso del job, no un sleep.
    Bus::assertDispatched(RespondWithAdvisor::class, fn (RespondWithAdvisor $job) => $job->delay instanceof DateTimeInterface
        && abs(now()->diffInSeconds($job->delay) - 5) <= 1);
});

it('la espera del asesor en 0 no añade retraso y la del canal manda si es mayor', function (int $channelDelay, int $typingDelay, int $expected) {
    stypCtx(['advisor_reply_delay' => $channelDelay], typingDelay: $typingDelay);
    Bus::fake();

    stypPost('instagram');

    Bus::assertDispatched(RespondWithAdvisor::class, fn (RespondWithAdvisor $job) => $job->delay instanceof DateTimeInterface
        && abs(now()->diffInSeconds($job->delay) - $expected) <= 1);
})->with([
    'asesor 0, canal 0' => [0, 0, 0],
    'canal 10 > asesor 3' => [10, 3, 10],
    'asesor 8 > canal 2' => [2, 8, 8],
]);

it('si el canal rechaza o no responde al «escribiendo», el asesor contesta igual y no se lanza ningún error', function () {
    [$inst] = stypCtx(typingFails: true);

    stypPost('whatsapp');

    $reply = app(CurrentInstitution::class)->runFor($inst->id, fn () => SocialMessage::query()->where('sender_type', 'bot')->sole());
    expect($reply->body)->toBe('Sí, hay cupo.')->and($reply->status)->toBe('sent');
});

it('no muestra «escribiendo» si el asesor no va a contestar (asesor apagado en el canal)', function () {
    stypCtx(['advisor_enabled' => false]);

    stypPost('messenger');

    expect(stypTypingRequests())->toBe([]);
});

it('aislamiento: un mensaje de otra institución nunca dispara el «escribiendo»', function () {
    [$instA] = stypCtx();
    Bus::fake();
    stypPost('messenger');
    $messageId = app(CurrentInstitution::class)->runFor($instA->id, fn () => (int) SocialMessage::query()->where('direction', 'inbound')->value('id'));

    $instB = Institution::factory()->create();
    (new SendAdvisorTyping($messageId, $instB->id))->handle();
    expect(stypTypingRequests())->toBe([]);

    // Con su propia institución sí sale.
    (new SendAdvisorTyping($messageId, $instA->id))->handle();
    expect(stypTypingRequests())->toHaveCount(1);
    expect(app(AdvisorTypingIndicator::class)->forMessage(999_999_999))->toBeFalse();
});
