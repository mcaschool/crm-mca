<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Integrations\Models\Integration;
use Throwable;

/**
 * Adapter de TRANSPORTE para proveedores compatibles con la API de OpenAI (Qwen via
 * DashScope compatible-mode, OpenAI, DeepSeek, Kimi). Sabe CÓMO expresar en esta API
 * concreta lo que el agente pide de forma conceptual (structured output, thinking,
 * cache, límites), consultando el ModelProfile para NO enviar nada que el modelo no
 * soporte (capability desconocida = desactivada). La clave se lee del almacén cifrado
 * y NUNCA se registra. No conoce a Celia: solo transporta.
 */
class OpenAiCompatibleChatClient implements AiChatClient
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function chat(Integration $integration, string $model, array $messages, array $params = [], ?AiExecutionContext $context = null): AiChatResponse
    {
        $apiKey = (string) $integration->secret('api_key');
        if ($apiKey === '') {
            throw new AiProviderException(AiErrorCategory::AuthenticationError, message: 'La integracion de IA no tiene API key configurada.');
        }

        $provider = (string) ($integration->provider ?? 'qwen');
        $profile = $this->catalog->profile($provider, $model);

        // Este adapter solo habla OpenAI-compatible: no fingir compatibilidad con otros
        // transportes (Anthropic/Gemini tendrán su propio adapter).
        if (! $profile->isOpenAiCompatible()) {
            throw new AiProviderException(AiErrorCategory::ProviderUnavailable, message: 'Transporte "'.$profile->transport.'" no soportado por este adapter.');
        }

        $baseUrl = $this->normalizeBaseUrl(
            (string) ($integration->secret('base_url') ?: config("ai_models.providers.{$provider}.default_base_url", 'https://dashscope.aliyuncs.com/compatible-mode/v1'))
        );

        $payload = $this->buildPayload($profile, $messages, $params);

        $start = (int) (microtime(true) * 1000);

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) ($params['timeout'] ?? 30))
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (Throwable $e) {
            // Fallo de red (timeout/DNS/TLS). NUNCA se registra la API key ni el prompt.
            Log::warning('ai.chat: fallo de red al proveedor', [
                'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl, 'error' => $e->getMessage(),
            ]);
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            throw new AiProviderException(
                $timeout ? AiErrorCategory::Timeout : AiErrorCategory::ProviderUnavailable,
                message: 'No se pudo contactar con el proveedor de IA.',
            );
        }

        $latency = (int) (microtime(true) * 1000) - $start;

        if (! $response->successful()) {
            return $this->fail($profile, $provider, $model, $baseUrl, $response);
        }

        return $this->toResponse($profile, $provider, $model, $response->json(), $latency);
    }

    /**
     * Construye el payload GATEADO por capacidades. Solo se envían optimizaciones que el
     * modelo declara soportar; los overrides del proceso no pueden habilitar lo no soportado.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildPayload(ModelProfile $profile, array $messages, array $params): array
    {
        $maxTokens = (int) ($params['max_tokens'] ?? 500);
        if ($profile->maxOutput() !== null) {
            $maxTokens = min($maxTokens, $profile->maxOutput());
        }

        $payload = [
            'model' => $profile->model,
            'messages' => $messages,
            'temperature' => $params['temperature'] ?? 0.3,
            'max_tokens' => $maxTokens,
        ];

        // Salida estructurada: intención conceptual del agente ('structured'; 'json' legado),
        // solo si el modelo la soporta.
        $wantsStructured = (bool) ($params['structured'] ?? $params['json'] ?? false);
        if ($wantsStructured && $profile->supportsStructuredOutput()) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // Thinking: solo si el modelo lo soporta. En NO-streaming (este adapter) Qwen exige
        // enable_thinking=false; por eso, aunque un proceso pida thinking, aquí va false hasta
        // que exista streaming. Si el modelo no soporta thinking, NO se envía el parámetro.
        if ($profile->supportsThinking()) {
            $payload['enable_thinking'] = false;
        }

        // Cache de contexto IMPLÍCITO (p. ej. Qwen ≥ min_prefix_tokens): es AUTOMÁTICO en el
        // proveedor; no requiere payload ni IDs de caché artificiales. El adapter solo se
        // asegura de leer los tokens en caché al normalizar el usage.

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $data
     */
    private function toResponse(ModelProfile $profile, string $provider, string $model, ?array $data, int $latency): AiChatResponse
    {
        $data = is_array($data) ? $data : [];
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AiChatResponse(
            content: (string) ($data['choices'][0]['message']['content'] ?? ''),
            provider: $provider,
            model: (string) ($data['model'] ?? $model),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            latencyMs: $latency,
            cachedInputTokens: (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0),
            reasoningTokens: (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0),
        );
    }

    private function fail(ModelProfile $profile, string $provider, string $model, string $baseUrl, \Illuminate\Http\Client\Response $response): never
    {
        $error = is_array($response->json('error')) ? $response->json('error') : [];
        $code = isset($error['code']) ? (string) $error['code'] : null;
        $message = $error['message'] ?? (is_string($response->json('message')) ? $response->json('message') : null);
        $requestId = $response->json('request_id')
            ?? ($error['id'] ?? null)
            ?? ($response->header('x-request-id') ?: ($response->header('X-Request-Id') ?: null));
        $category = $profile->categorize($response->status(), $code);

        // Diagnóstico SANEADO: nunca la API key, el header Authorization ni el prompt.
        Log::warning('ai.chat: el proveedor respondió error', [
            'provider' => $provider,
            'model' => $model,
            'base_url' => $baseUrl,
            'status' => $response->status(),
            'category' => $category->value,
            'error_code' => $code,
            'error_message' => $message,
            'request_id' => $requestId,
        ]);

        throw new AiProviderException(
            $category,
            httpStatus: $response->status(),
            providerCode: $code,
            requestId: is_string($requestId) ? $requestId : null,
            message: 'El proveedor de IA respondió '.$response->status().' ('.$category->value.').',
        );
    }

    /**
     * Normaliza la Base URL para que la ruta final sea SIEMPRE <base>/chat/completions.
     * Tolera barra final y que el admin haya pegado la ruta completa por error.
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
