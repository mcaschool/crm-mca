<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de publicar en UNA red. Independiente por red: una puede ser ok y la otra fallar.
 */
final readonly class PublishResult
{
    private function __construct(
        public bool $ok,
        public ?string $externalId,
        public ?string $containerId,
        public ?string $error,
    ) {}

    public static function ok(string $externalId, ?string $containerId = null): self
    {
        return new self(true, $externalId, $containerId, null);
    }

    public static function fail(string $error, ?string $containerId = null): self
    {
        return new self(false, null, $containerId, $error);
    }
}
