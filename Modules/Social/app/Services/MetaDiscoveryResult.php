<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado del descubrimiento de activos de «Conectar Meta»: la lista de Páginas
 * detectadas (cada una con su Instagram asociado, si lo hay). Los tokens viven dentro de
 * cada MetaDiscoveredPage y nunca se serializan hacia el navegador.
 */
final readonly class MetaDiscoveryResult
{
    /**
     * @param  list<MetaDiscoveredPage>  $pages
     */
    public function __construct(
        public array $pages,
    ) {}

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }

    /**
     * Proyección SEGURA para la UI: solo datos no sensibles de cada Página.
     *
     * @return list<array{page_id: string, name: string, has_messenger: bool, has_instagram: bool, instagram_username: string|null}>
     */
    public function forDisplay(): array
    {
        return array_map(static fn (MetaDiscoveredPage $page): array => $page->forDisplay(), $this->pages);
    }
}
