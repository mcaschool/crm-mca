<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Enums\AiErrorCategory;

/**
 * Capacidades RESUELTAS de un modelo (generic ← provider ← model). Es SOLO un
 * value object que representa qué admite el modelo; NO conoce a Celia ni a los
 * agentes, ni traduce HTTP (eso vive en el transport/adapter). Los adapters lo
 * consultan para construir el payload compatible.
 */
final class ModelProfile
{
    /**
     * @param  array<string,mixed>  $capabilities
     * @param  array<string,mixed>  $limits
     * @param  array<string,string>  $errorMap
     * @param  array<string,mixed>  $usageMap
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $transport,
        public readonly string $label,
        public readonly string $status,   // supported | deprecated | unknown
        public readonly bool $known,      // el modelo estaba catalogado (no genérico)
        private readonly array $capabilities,
        private readonly array $limits,
        private readonly array $errorMap,
        private readonly array $usageMap,
    ) {}

    public function supportsStreaming(): bool
    {
        return (bool) data_get($this->capabilities, 'streaming', false);
    }

    public function supportsStructuredOutput(): bool
    {
        return (bool) data_get($this->capabilities, 'structured_output', false);
    }

    public function supportsThinking(): bool
    {
        return (bool) data_get($this->capabilities, 'thinking.supported', false);
    }

    public function thinkingDefault(): bool
    {
        return (bool) data_get($this->capabilities, 'thinking.default', false);
    }

    public function supportsImplicitCache(): bool
    {
        return (bool) data_get($this->capabilities, 'context_cache.implicit', false);
    }

    public function supportsExplicitCache(): bool
    {
        return (bool) data_get($this->capabilities, 'context_cache.explicit', false);
    }

    public function cacheMinPrefixTokens(): ?int
    {
        $v = data_get($this->capabilities, 'context_cache.min_prefix_tokens');

        return $v === null ? null : (int) $v;
    }

    public function contextWindow(): ?int
    {
        $v = data_get($this->limits, 'context_window');

        return $v === null ? null : (int) $v;
    }

    public function maxOutput(): ?int
    {
        $v = data_get($this->limits, 'max_output');

        return $v === null ? null : (int) $v;
    }

    public function isOpenAiCompatible(): bool
    {
        return $this->transport === 'openai_compatible';
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** @return array<string,string> */
    public function errorMap(): array
    {
        return $this->errorMap;
    }

    /** @return array<string,mixed> */
    public function usageMap(): array
    {
        return $this->usageMap;
    }

    /** Traduce un error del proveedor a categoría normalizada según su error_map. */
    public function categorize(int $status, ?string $providerCode): AiErrorCategory
    {
        return AiErrorCategory::fromResponse($status, $providerCode, $this->errorMap);
    }
}
