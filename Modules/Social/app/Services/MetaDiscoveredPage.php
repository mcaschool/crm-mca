<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Un activo detectado durante «Conectar Meta»: una Página de Facebook (que habilita
 * Messenger) con su Instagram Professional asociado, si lo hay.
 *
 * El Page Access Token vive AQUÍ (server-side) pero NUNCA se expone a la vista: la UI
 * consume solo forDisplay(), que omite el token. En esta etapa el token no se persiste
 * (solo login + descubrimiento); Bloques posteriores decidirán su almacenamiento cifrado.
 */
final readonly class MetaDiscoveredPage
{
    public function __construct(
        public string $pageId,
        public string $name,
        public string $pageAccessToken,
        public ?string $instagramId = null,
        public ?string $instagramUsername = null,
    ) {}

    public function hasInstagram(): bool
    {
        return $this->instagramId !== null && $this->instagramId !== '';
    }

    /**
     * Proyección SEGURA para la UI y para el snapshot de Livewire: sin token ni secretos.
     *
     * @return array{page_id: string, name: string, has_messenger: bool, has_instagram: bool, instagram_username: string|null}
     */
    public function forDisplay(): array
    {
        return [
            'page_id' => $this->pageId,
            'name' => $this->name,
            'has_messenger' => true, // toda Página habilita Messenger
            'has_instagram' => $this->hasInstagram(),
            'instagram_username' => $this->instagramUsername,
        ];
    }
}
