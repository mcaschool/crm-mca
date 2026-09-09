<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de publicar en UNA red. Independiente por red: una puede ser ok y la otra fallar.
 * 'processing' es el estado RECUPERABLE de video en Instagram: el contenedor existe y es
 * válido, pero Meta aún lo procesa — se conserva el containerId para reanudar después
 * (jamás se marca failed ni se crea otro contenedor por esto).
 */
final readonly class PublishResult
{
    private function __construct(
        public bool $ok,
        public ?string $externalId,
        public ?string $containerId,
        public ?string $error,
        public bool $processing = false,
    ) {}

    public static function ok(string $externalId, ?string $containerId = null): self
    {
        return new self(true, $externalId, $containerId, null);
    }

    public static function fail(string $error, ?string $containerId = null): self
    {
        return new self(false, null, $containerId, $error);
    }

    public static function processing(string $message, string $containerId): self
    {
        return new self(false, null, $containerId, $message, true);
    }
}
