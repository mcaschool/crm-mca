<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Ai\Services\AiProviderException;
use Modules\Ai\Services\ModelCatalog;
use Modules\Ai\Services\OpenAiCompatibleChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

function capIntegration(string $provider, string $baseUrl = 'https://ws-x.maas.aliyuncs.com/compatible-mode/v1'): Integration
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, fn () => Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => $baseUrl])
        ->create(['type' => 'ai_provider', 'provider' => $provider, 'status' => 'active']));
}

function catalog(): ModelCatalog
{
    return app(ModelCatalog::class);
}

// ---------------------------------------------------- ModelCatalog / ModelProfile

it('resuelve el perfil verificado de qwen3.7-plus (overrides del modelo sobre el proveedor)', function () {
    $p = catalog()->profile('qwen', 'qwen3.7-plus');

    expect($p->known)->toBeTrue()
        ->and($p->status)->toBe('supported')
        ->and($p->transport)->toBe('openai_compatible')
        ->and($p->supportsStructuredOutput())->toBeTrue()   // override del modelo (provider default: false)
        ->and($p->supportsThinking())->toBeTrue()
        ->and($p->supportsImplicitCache())->toBeTrue()
        ->and($p->supportsExplicitCache())->toBeFalse()
        ->and($p->cacheMinPrefixTokens())->toBe(1024)
        ->and($p->maxOutput())->toBe(8192)
        ->and($p->contextWindow())->toBe(131072);
});

it('un modelo Qwen NO catalogado usa defaults seguros del proveedor (sin copiar capacidades de otros)', function () {
    $p = catalog()->profile('qwen', 'qwen-nuevo-2027');

    expect($p->known)->toBeFalse()
        ->and($p->status)->toBe('unknown')
        ->and($p->transport)->toBe('openai_compatible')  // sigue pudiendo llamar estándar
        ->and($p->supportsStructuredOutput())->toBeFalse() // provider default (no hereda de qwen3.7-plus)
        ->and($p->supportsThinking())->toBeFalse()
        ->and($p->supportsImplicitCache())->toBeFalse();
});

it('un proveedor NO catalogado cae al perfil genérico seguro', function () {
    $p = catalog()->profile('proveedor-x', 'modelo-x');

    expect($p->known)->toBeFalse()
        ->and($p->transport)->toBe('openai_compatible')
        ->and($p->supportsStructuredOutput())->toBeFalse()
        ->and($p->supportsThinking())->toBeFalse()
        ->and($p->supportsExplicitCache())->toBeFalse();
});

// ---------------------------------------------------- Transport: gating por capacidad

it('modelo desconocido: NO envía response_format ni enable_thinking aunque se pida json', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);
    $integration = capIntegration('qwen');

    app(OpenAiCompatibleChatClient::class)->chat($integration, 'qwen-nuevo-2027', [['role' => 'user', 'content' => 'hi']], ['json' => true]);

    Http::assertSent(fn ($r) => ! array_key_exists('response_format', $r->data())
        && ! array_key_exists('enable_thinking', $r->data()));
});

it('capa un max_tokens excesivo al máximo del modelo (qwen3.7-plus = 8192)', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);
    $integration = capIntegration('qwen');

    app(OpenAiCompatibleChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']], ['max_tokens' => 999999]);

    Http::assertSent(fn ($r) => $r['max_tokens'] === 8192);
});

// ---------------------------------------------------- Transport: usage normalizado

it('normaliza usage con cached_tokens y reasoning_tokens', function () {
    Http::fake(['*' => Http::response([
        'model' => 'qwen3.7-plus',
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => [
            'prompt_tokens' => 3019,
            'completion_tokens' => 104,
            'prompt_tokens_details' => ['cached_tokens' => 2048],
            'completion_tokens_details' => ['reasoning_tokens' => 60],
        ],
    ], 200)]);
    $integration = capIntegration('qwen');

    $res = app(OpenAiCompatibleChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']]);

    expect($res->inputTokens())->toBe(3019)
        ->and($res->cachedInputTokens)->toBe(2048)
        ->and($res->uncachedInputTokens())->toBe(971)
        ->and($res->outputTokens())->toBe(104)
        ->and($res->reasoningTokens)->toBe(60);

    $meta = $res->meta();
    expect($meta['cached_input_tokens'])->toBe(2048)
        ->and($meta['reasoning_tokens'])->toBe(60)
        ->and($meta['input_tokens'])->toBe(3019);
});

// ---------------------------------------------------- Transport: error normalizado

it('traduce 403 insufficient_quota a AiProviderException(quota_exhausted) con request id', function () {
    Http::fake(['*' => Http::response([
        'error' => ['message' => 'Free quota exhausted.', 'id' => 'req-123', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota'],
    ], 403)]);
    $integration = capIntegration('qwen');

    try {
        app(OpenAiCompatibleChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']], ['json' => true]);
        expect(false)->toBeTrue('debió lanzar');
    } catch (AiProviderException $e) {
        expect($e->category)->toBe(AiErrorCategory::QuotaExhausted)
            ->and($e->httpStatus)->toBe(403)
            ->and($e->providerCode)->toBe('insufficient_quota')
            ->and($e->requestId)->toBe('req-123')
            ->and($e->getMessage())->not->toContain('sk-real-key');
    }
});

// ---------------------------------------------------- Enum de categorías

it('AiErrorCategory: el error_map del proveedor tiene precedencia sobre el status', function () {
    $map = ['insufficient_quota' => 'quota_exhausted'];

    expect(AiErrorCategory::fromResponse(403, 'insufficient_quota', $map))->toBe(AiErrorCategory::QuotaExhausted)
        ->and(AiErrorCategory::fromResponse(403, null, []))->toBe(AiErrorCategory::AuthenticationError)
        ->and(AiErrorCategory::fromResponse(429, null, []))->toBe(AiErrorCategory::RateLimited)
        ->and(AiErrorCategory::fromResponse(404, null, []))->toBe(AiErrorCategory::ModelUnavailable)
        ->and(AiErrorCategory::fromResponse(500, null, []))->toBe(AiErrorCategory::ProviderUnavailable);
});
