<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Social\Livewire\Concerns\ConnectsMeta;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\SocialChannel;

/**
 * «Conectar Meta» — onboarding visual (Facebook Login for Business) que descubre las
 * Páginas de Facebook (Messenger) e Instagram Professional asociado de la institución.
 *
 * La autorización termina en una CONEXIÓN de la empresa utilizable por Formularios publicitarios
 * (MetaConnectionService::connect). NO crea ni sobreescribe canales ni webhooks: la conexión de
 * Messenger/Instagram operativa no se toca, y una autorización fallida no sustituye a la buena. Toda
 * la lógica Graph vive en MetaConnectionService; aquí solo se coordina la UX. Los Page
 * Access Tokens nunca llegan a propiedades públicas (no se serializan al navegador): las
 * tarjetas se pintan con la proyección segura forDisplay().
 */
#[Layout('layouts.app')]
class MetaConnect extends Component
{
    use ConnectsMeta;

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

    /**
     * Recibe el state + lo que devolvió Facebook Login for Business (code de System User o token de
     * usuario), valida y GUARDA la conexión de la empresa, y muestra lo detectado.
     */
    public function discover(string $state, string $code = '', string $accessToken = ''): void
    {
        $this->errorMessage = '';
        $result = $this->completeMetaConnection($state, $code, $accessToken);
        if (is_string($result)) {
            $this->errorMessage = $result;

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
     * Confirmación. La conexión ya quedó guardada al autorizar; no se crea ni modifica ningún
     * canal (Messenger/Instagram siguen como estaban).
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
            'connection' => MetaConnection::query()->first(), // la de esta empresa (ámbito)
        ]);
    }
}
