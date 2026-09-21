<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use RuntimeException;

/**
 * «Conectar Meta» — onboarding visual (Facebook Login for Business) que descubre las
 * Páginas de Facebook (Messenger) e Instagram Professional asociado de la institución.
 *
 * Esta etapa hace SOLO login + descubrimiento + visualización: NO crea ni sobreescribe
 * canales, no toca tokens ni webhooks, y convive con la conexión Meta ya operativa. Toda
 * la lógica Graph vive en MetaConnectionService; aquí solo se coordina la UX. Los Page
 * Access Tokens nunca llegan a propiedades públicas (no se serializan al navegador): las
 * tarjetas se pintan con la proyección segura forDisplay().
 */
#[Layout('layouts.app')]
class MetaConnect extends Component
{
    /** idle → select → done. */
    public string $step = 'idle';

    /**
     * Activos detectados, SIN datos sensibles (sin tokens).
     *
     * @var list<array{page_id: string, name: string, has_messenger: bool, has_instagram: bool, instagram_username: string|null}>
     */
    public array $pages = [];

    public ?string $selectedPageId = null;

    public string $errorMessage = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
    }

    /** La plataforma Meta está lista (config de plataforma presente). */
    public function platformReady(): bool
    {
        return app(MetaConnectionService::class)->isPlatformConfigured();
    }

    /**
     * Datos públicos para el SDK de Facebook (App ID + Configuration ID + versión).
     *
     * @return array{app_id: string, config_id: string, version: string}
     */
    public function browserConfig(): array
    {
        return app(MetaConnectionService::class)->browserConfig();
    }

    /**
     * Emite el state anti-CSRF (lo pide el JS AL PULSAR el botón; nunca se genera en el
     * navegador). Un solo uso, ligado a este usuario y su institución. null si el usuario
     * no puede administrar canales o la plataforma no está lista.
     */
    public function connectState(): ?string
    {
        $service = app(MetaConnectionService::class);
        $user = auth()->user();
        if (! $service->isPlatformConfigured() || $user === null || ! $user->can('create', SocialChannel::class)) {
            return null;
        }

        $institutionId = app(CurrentInstitution::class)->id();
        if ($institutionId === null) {
            return null;
        }

        return $service->issueState((int) $user->id, $institutionId);
    }

    /**
     * Recibe el state + authorization code del navegador, valida el state (un solo uso,
     * misma institución) e intercambia el código server-side para descubrir los activos.
     */
    public function discover(string $state, string $code): void
    {
        $this->authorize('create', SocialChannel::class);
        $this->errorMessage = '';

        $service = app(MetaConnectionService::class);
        $user = auth()->user();
        $context = app(CurrentInstitution::class);

        $institutionId = $user !== null ? $service->consumeState((int) $user->id, $state) : null;
        if ($institutionId === null || $institutionId !== $context->id()) {
            $this->errorMessage = __('La conexión expiró o no es válida. Vuelve a iniciar el proceso.');

            return;
        }

        try {
            $result = $service->discoverAssets($code);
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->pages = $result->forDisplay();
        $this->selectedPageId = $this->pages[0]['page_id'] ?? null;
        $this->step = 'select';
    }

    public function select(string $pageId): void
    {
        foreach ($this->pages as $page) {
            if ($page['page_id'] === $pageId) {
                $this->selectedPageId = $pageId;

                return;
            }
        }
    }

    /**
     * Confirmación de la selección. En esta etapa NO persiste ningún canal: solo valida que
     * el login y el descubrimiento funcionaron (la creación de canales llega en un bloque
     * posterior). No toca la conexión Meta existente.
     */
    public function confirm(): void
    {
        $this->authorize('create', SocialChannel::class);
        if ($this->selectedPageId === null || $this->pages === []) {
            $this->errorMessage = __('Selecciona una cuenta para continuar.');

            return;
        }

        $this->step = 'done';
    }

    public function restart(): void
    {
        $this->reset(['step', 'pages', 'selectedPageId', 'errorMessage']);
    }

    public function render(): View
    {
        return view('social::meta-connect', [
            'selectedPage' => collect($this->pages)->firstWhere('page_id', $this->selectedPageId),
        ]);
    }
}
