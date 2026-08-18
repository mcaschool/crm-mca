<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Ai\Services\OpenAiCompatibleChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * Capa de conexion (sin logica de Celia): arma <base>/chat/completions sobre la
 * Base URL configurable, autentica con Bearer y respeta parametros de Qwen3.
 */
function aiIntegration(string $provider, string $baseUrl): Integration
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, fn () => Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => $baseUrl])
        ->create(['type' => 'ai_provider', 'provider' => $provider, 'status' => 'active']));
}

it('POSTea a <base>/chat/completions con Bearer y enable_thinking=false para Qwen', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'qwen3.7-plus',
            'choices' => [['message' => ['content' => '{"reply":"ok","action":"answer"}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ], 200),
    ]);

    $base = 'https://ws-e5k52dsi23yw71v9.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1';
    $integration = aiIntegration('qwen', $base);

    $client = new OpenAiCompatibleChatClient;
    $res = $client->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hola']], ['json' => true]);

    expect($res->content)->toBe('{"reply":"ok","action":"answer"}');
    expect($res->model)->toBe('qwen3.7-plus');
    expect($res->promptTokens)->toBe(100);

    Http::assertSent(function ($request) use ($base) {
        return $request->url() === $base.'/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-real-key')
            && $request['model'] === 'qwen3.7-plus'
            && $request['enable_thinking'] === false
            && $request['response_format']['type'] === 'json_object';
    });
});

it('tolera una Base URL con barra final o con /chat/completions pegado', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200)]);

    $base = 'https://ws-e5k52dsi23yw71v9.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1';
    $integration = aiIntegration('qwen', $base.'/chat/completions/');

    (new OpenAiCompatibleChatClient)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'hi']]);

    Http::assertSent(fn ($request) => $request->url() === $base.'/chat/completions');
});

it('NO envia enable_thinking para proveedores que no son Qwen (p. ej. openai)', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200)]);

    $integration = aiIntegration('openai', 'https://api.openai.com/v1');

    (new OpenAiCompatibleChatClient)->chat($integration, 'gpt-5-mini', [['role' => 'user', 'content' => 'hi']]);

    Http::assertSent(fn ($request) => ! array_key_exists('enable_thinking', $request->data()));
});
