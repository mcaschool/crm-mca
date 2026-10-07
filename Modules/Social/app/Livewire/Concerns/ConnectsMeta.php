<?php

declare(strict_types=1);

namespace Modules\Social\Livewire\Concerns;

use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use Modules\Social\Services\MetaDiscoveryResult;
use RuntimeException;
use Throwable;

/**
 * Botón «Conectar Meta» (Facebook Login for Business) para cualquier pantalla del panel: el
 * state anti-CSRF lo emite el backend (un solo uso, ligado al usuario y su empresa) y la
 * autorización se valida y GUARDA como la conexión de la empresa en MetaConnectionService.
 * El cliente nunca copia tokens; el code/token no se guarda en propiedades públicas.
 */
trait ConnectsMeta
{
    /** La plataforma Meta está lista (config de plataforma presente). */
    public function platformReady(): bool
    {
        return app(MetaConnectionService::class)->isPlatformConfigured();
    }

    /**
     * Datos públicos para el SDK de Facebook (App ID + Configuration ID + versión).
     *
     * @return array{app_id: string, config_id: string, version: string, token_type: string}
     */
    public function browserConfig(): array
    {
        return app(MetaConnectionService::class)->browserConfig();
    }

    /**
     * Emite el state anti-CSRF (lo pide el JS AL PULSAR el botón; nunca se genera en el
     * navegador). null si el usuario no puede administrar canales o la plataforma no está lista.
     */
    public function connectState(): ?string
    {
        $service = app(MetaConnectionService::class);
        $user = auth()->user();
        if (! $service->isPlatformConfigured() || $user === null || ! $user->can('create', SocialChannel::class)) {
            return null;
        }

        $institutionId = app(CurrentInstitution::class)->id();

        return $institutionId === null ? null : $service->issueState((int) $user->id, $institutionId);
    }

    /**
     * Valida el state y la autorización y la guarda como la conexión de la empresa. Si algo falla
     * no se guarda nada (la conexión anterior sigue) y se devuelve el motivo en lenguaje llano.
     */
    protected function completeMetaConnection(string $state, string $code, string $accessToken): MetaDiscoveryResult|string
    {
        $this->authorize('create', SocialChannel::class);

        $service = app(MetaConnectionService::class);
        $user = auth()->user();
        $institutionId = $user !== null ? $service->consumeState((int) $user->id, $state) : null;
        if ($user === null || $institutionId === null || $institutionId !== app(CurrentInstitution::class)->id()) {
            return __('La conexión expiró o no es válida. Vuelve a iniciar el proceso.');
        }

        try {
            [, $result] = $service->connect($institutionId, (int) $user->id, $code, $accessToken);

            return $result;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            Log::warning('social.meta.connect: no se pudo guardar la conexión', ['error' => $e->getMessage()]);

            return __('No se pudo guardar la conexión con Meta. Tu conexión anterior sigue activa; inténtalo de nuevo.');
        }
    }
}
