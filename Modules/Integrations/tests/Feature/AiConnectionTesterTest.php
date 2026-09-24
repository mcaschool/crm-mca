<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Integrations\Services\ConnectionTester;

/**
 * El test de una integración de IA distingue endpoint/credencial (/models) de
 * GENERACIÓN real (modelo configurado), y reporta fallos por categoría normalizada.
 * /models=200 NO basta para declararla operativa.
 */
function ctCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => 'https://ws-x.maas.aliyuncs.com/compatible-mode/v1'])
        ->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);

    return [$institution, $integration];
}

it('con /models 200 pero SIN proceso configurado: OK pero indica que el modelo no se probó', function () {
    Http::fake(['*/models' => Http::response(['data' => [['id' => 'qwen3.7-plus']]], 200)]);
    [, $integration] = ctCtx();

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('generacion no probada');
});

it('con /models 200 y generación 200: operativa', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'qwen3.7-plus']]], 200),
        '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200),
    ]);
    [$institution, $integration] = ctCtx();
    AiProcessConfig::query()->create([
        'institution_id' => $institution->id,
        'bot_id' => null,
        'process' => 'conversation',
        'integration_id' => $integration->id,
        'model' => 'qwen3.7-plus',
        'status' => 'active',
    ]);

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('qwen3.7-plus: OK');
});

it('valida cada asignación proceso→modelo por separado (una falla ⇒ integración no operativa)', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'qwen3.7-plus']]], 200),
        '*/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'OK']]]], 200)     // conversation OK
            ->push(['error' => ['code' => 'insufficient_quota']], 403),          // summary sin cuota
    ]);
    [$institution, $integration] = ctCtx();
    foreach ([['conversation', 'qwen3.7-plus'], ['summary', 'qwen3.8-max']] as [$process, $model]) {
        AiProcessConfig::query()->create([
            'institution_id' => $institution->id, 'bot_id' => null,
            'process' => $process, 'integration_id' => $integration->id, 'model' => $model, 'status' => 'active',
        ]);
    }

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('qwen3.7-plus: OK')
        ->and($result->message)->toContain('qwen3.8-max: quota_exhausted');
});

it('con /models 200 y generación 403 insufficient_quota: falla con categoría quota_exhausted', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'qwen3.7-plus']]], 200),
        '*/chat/completions' => Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'Free quota exhausted.']], 403),
    ]);
    [$institution, $integration] = ctCtx();
    AiProcessConfig::query()->create([
        'institution_id' => $institution->id,
        'bot_id' => null,
        'process' => 'conversation',
        'integration_id' => $integration->id,
        'model' => 'qwen3.7-plus',
        'status' => 'active',
    ]);

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('quota_exhausted');
});

it('con /models 401: falla con categoría authentication_error', function () {
    Http::fake(['*/models' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);
    [, $integration] = ctCtx();

    $result = app(ConnectionTester::class)->test($integration);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('authentication_error');
});
