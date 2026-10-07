<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use RuntimeException;
use Throwable;

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
     * Recibe el state + lo que devolvió Facebook Login for Business, valida el state (un solo
     * uso, misma institución) y descubre los activos. Soporta los dos tipos de configuración:
     * un authorization code (System User → intercambio server-side) o un access token (User
     * Access Token → uso directo). El token/código nunca se guarda en propiedades públicas.
     */
    public function discover(string $state, string $code = '', string $accessToken = ''): void
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

        // Valida la autorización y la GUARDA como la conexión de la empresa (Formularios
        // publicitarios). Si algo falla no se guarda nada y la conexión anterior sigue intacta.
        try {
            [, $result] = $service->connect($institutionId, (int) $user->id, $code, $accessToken);
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        } catch (Throwable $e) {
            Log::warning('social.meta.connect: no se pudo guardar la conexión', ['error' => $e->getMessage()]);
            $this->errorMessage = __('No se pudo guardar la conexión con Meta. Tu conexión anterior sigue activa; inténtalo de nuevo.');

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
