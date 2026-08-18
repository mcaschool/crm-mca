<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Respuesta de una llamada de chat a un proveedor de IA. Los tokens y la latencia
 * alimentan messages.meta (base del AI Deflection Rate).
 */
final class AiChatResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
    ) {}

    /**
     * meta que se guarda en messages.meta (sin secretos).
     *
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
