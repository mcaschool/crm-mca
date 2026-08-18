<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Http;
use Modules\Integrations\Models\Integration;
use RuntimeException;

/**
 * Cliente de chat para proveedores compatibles con la API de OpenAI (Qwen via
 * DashScope compatible-mode, OpenAI, DeepSeek, Kimi). La clave se lee del almacen
 * cifrado de integraciones (nunca hardcoded). No conoce a Celia: solo transporta.
 */
class OpenAiCompatibleChatClient implements AiChatClient
{
    private const DEFAULT_BASE = [
        'openai' => 'https://api.openai.com/v1',
        'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'deepseek' => 'https://api.deepseek.com',
        'kimi' => 'https://api.moonshot.cn/v1',
    ];

    public function chat(Integration $integration, string $model, array $messages, array $params = []): AiChatResponse
    {
        $apiKey = (string) $integration->secret('api_key');
        if ($apiKey === '') {
            throw new RuntimeException('La integracion de IA no tiene API key configurada.');
        }

        $provider = (string) ($integration->provider ?? 'qwen');
        $baseUrl = $this->normalizeBaseUrl(
            (string) ($integration->secret('base_url') ?: (self::DEFAULT_BASE[$provider] ?? self::DEFAULT_BASE['qwen']))
        );

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $params['temperature'] ?? 0.3,
            'max_tokens' => $params['max_tokens'] ?? 500,
        ];
        if (! empty($params['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // enable_thinking: los modelos Qwen3 en modo compatible NO-streaming exigen
        // este flag en false (el "pensamiento" solo funciona con streaming). Se envia
        // solo a Qwen para no ensuciar el payload de otros proveedores (OpenAI rechaza
        // parametros desconocidos). Se puede sobreescribir por config del proceso.
        if (array_key_exists('enable_thinking', $params)) {
            $payload['enable_thinking'] = (bool) $params['enable_thinking'];
        } elseif ($provider === 'qwen') {
            $payload['enable_thinking'] = false;
        }

        $start = (int) (microtime(true) * 1000);

        $response = Http::withToken($apiKey)
            ->timeout((int) ($params['timeout'] ?? 30))
            ->post($baseUrl.'/chat/completions', $payload);

        $latency = (int) (microtime(true) * 1000) - $start;

        if (! $response->successful()) {
            throw new RuntimeException('El proveedor de IA respondio '.$response->status().'.');
        }

        $data = $response->json();

        return new AiChatResponse(
            content: (string) ($data['choices'][0]['message']['content'] ?? ''),
            provider: $provider,
            model: (string) ($data['model'] ?? $model),
            promptTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
            latencyMs: $latency,
        );
    }

    /**
     * Normaliza la Base URL configurada para que la ruta final sea SIEMPRE
     * <base>/chat/completions. Tolera barra final y que el admin haya pegado por
     * error la ruta completa (…/chat/completions) en el campo Base URL.
     */
    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if (str_ends_with($baseUrl, '/chat/completions')) {
            $baseUrl = rtrim(substr($baseUrl, 0, -strlen('/chat/completions')), '/');
        }

        return $baseUrl;
    }
}
