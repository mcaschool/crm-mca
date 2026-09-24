<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Ai\Models\AiUsageEvent;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiExecutionContext;
use Modules\Ai\Services\AiProviderException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * @return array{0: Institution, 1: Integration}
 */
function obsCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => 'https://ws-x.maas.aliyuncs.com/compatible-mode/v1'])
        ->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);

    return [$institution, $integration];
}

it('el decorador registra telemetría de usage EXITOSA con métricas normalizadas', function () {
    Http::fake(['*' => Http::response([
        'model' => 'qwen3.7-plus',
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 50, 'prompt_tokens_details' => ['cached_tokens' => 1024]],
    ], 200)]);
    [$institution, $integration] = obsCtx();
    $ctx = new AiExecutionContext($institution->id, 'conversation', $integration->id, 'qwen', 'qwen3.7-plus', botId: 7);

    app(AiChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']], ['structured' => true], $ctx);

    $row = AiUsageEvent::withoutGlobalScopes()->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('success')
        ->and($row->institution_id)->toBe($institution->id)
        ->and($row->process)->toBe('conversation')
        ->and($row->bot_id)->toBe(7)
        ->and($row->provider)->toBe('qwen')
        ->and($row->model)->toBe('qwen3.7-plus')
        ->and($row->input_tokens)->toBe(1200)
        ->and($row->cached_input_tokens)->toBe(1024)
        ->and($row->uncached_input_tokens)->toBe(176)
        ->and($row->output_tokens)->toBe(50)
        ->and($row->error_category)->toBeNull();
});

it('ante fallo: registra un evento error POR CADA llamada con categoría normalizada', function () {
    Http::fake(['*' => Http::response([
        'error' => ['code' => 'insufficient_quota', 'message' => 'Free quota exhausted.', 'id' => 'req-1'],
    ], 403)]);
    [$institution, $integration] = obsCtx();
    $ctx = new AiExecutionContext($institution->id, 'conversation', $integration->id, 'qwen', 'qwen3.7-plus');

    foreach (range(1, 3) as $ignored) {
        try {
            app(AiChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']], [], $ctx);
        } catch (AiProviderException) {
            // esperado
        }
    }

    // La telemetría registra CADA ejecución (la deduplicación de alertas es del Bloque de alertas).
    expect(AiUsageEvent::withoutGlobalScopes()->where('status', 'error')->count())->toBe(3);
    $row = AiUsageEvent::withoutGlobalScopes()->where('status', 'error')->latest('id')->first();
    expect($row->error_category)->toBe('quota_exhausted');
});
