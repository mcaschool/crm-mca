<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

/**
 * Respuesta NORMALIZADA de una llamada de chat a cualquier proveedor. Cada adapter
 * traduce los nombres del proveedor a estos conceptos comunes. Alimenta messages.meta
 * (base del AI Deflection Rate y del análisis de coste/caché).
 *
 * promptTokens/completionTokens se conservan por compatibilidad; los conceptos
 * normativos son input/cached_input/uncached_input/output/reasoning.
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
        public readonly int $cachedInputTokens = 0,
        public readonly int $reasoningTokens = 0,
    ) {}

    public function inputTokens(): int
    {
        return $this->promptTokens;
    }

    public function uncachedInputTokens(): int
    {
        return max(0, $this->promptTokens - $this->cachedInputTokens);
    }

    public function outputTokens(): int
    {
        return $this->completionTokens;
    }

    /**
     * meta que se guarda en messages.meta (sin secretos). Incluye las claves
     * normalizadas y conserva las antiguas para no romper lecturas existentes.
     *
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            // Normalizadas.
            'input_tokens' => $this->promptTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'uncached_input_tokens' => $this->uncachedInputTokens(),
            'output_tokens' => $this->completionTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            // Compatibilidad hacia atrás.
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
