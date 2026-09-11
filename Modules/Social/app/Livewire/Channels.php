<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Support\SecretMasker;
use Modules\Social\Models\SocialChannel;

/**
 * Administración de canales sociales (Configuraciones). CRUD de los canales de la institución
 * activa (varias filas por proveedor: p. ej. 2-3 números de WhatsApp), con credenciales
 * CIFRADAS (encrypted:array). El token nunca se re-muestra completo: solo enmascarado; al
 * editar, dejar el campo vacío conserva el actual. Solo Admin (SocialChannelPolicy). El
 * scoping por institución lo da el scope global de SocialChannel.
 */
#[Layout('layouts.app')]
class Channels extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $provider = 'whatsapp';

    public string $display_name = '';

    public string $external_id = '';

    public string $token = '';

    /** WABA ID (solo WhatsApp): vive en credentials cifradas junto al token. NO es secreto. */
    public string $waba_id = '';

    public bool $is_active = true;

    public ?string $currentTokenMask = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
    }

    public function create(): void
    {
        $this->authorize('create', SocialChannel::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $channel = SocialChannel::query()->findOrFail($id);
        $this->authorize('update', $channel);

        $this->editingId = $channel->id;
        $this->provider = $channel->provider;
        $this->display_name = $channel->display_name;
        $this->external_id = (string) $channel->external_id;
        $this->waba_id = (string) (data_get($channel->credentials, 'waba_id') ?? '');
        $this->is_active = (bool) $channel->is_active;
        $this->token = '';                       // barrera: nunca se precarga el secreto
        $current = (string) (data_get($channel->credentials, 'token') ?? '');
        $this->currentTokenMask = $current !== '' ? SecretMasker::mask($current) : null;
        $this->showForm = true;
    }

    public function toggle(int $id): void
    {
        $channel = SocialChannel::query()->findOrFail($id);
        $this->authorize('update', $channel);

        $channel->is_active = ! $channel->is_active;
        $channel->save();
    }

    public function save(): void
    {
        $creating = $this->editingId === null;
        $creating
            ? $this->authorize('create', SocialChannel::class)
            : $this->authorize('update', SocialChannel::query()->findOrFail($this->editingId));

        $this->validate([
            'provider' => ['required', Rule::in(array_keys(SocialChannel::PROVIDERS))],
            'display_name' => ['required', 'string', 'max:120'],
            'external_id' => ['required', 'string', 'max:191'],
            'token' => [$creating ? 'required' : 'nullable', 'string'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'is_active' => ['boolean'],
        ]);

        // Unicidad (institución + provider + external_id); el índice único lo respalda.
        $duplicate = SocialChannel::query()
            ->where('provider', $this->provider)
            ->where('external_id', $this->external_id)
            ->when($this->editingId !== null, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();
        if ($duplicate) {
            $this->addError('external_id', __('Ya existe un canal de ese proveedor con ese identificador.'));

            return;
        }

        $channel = $creating ? new SocialChannel : SocialChannel::query()->findOrFail($this->editingId);
        if ($creating) {
            $channel->provider = $this->provider;   // el proveedor no cambia al editar
        }
        $channel->display_name = $this->display_name;
        $channel->external_id = $this->external_id;
        $channel->is_active = $this->is_active;

        // Credenciales cifradas: reemplazar el token solo si se escribió uno nuevo.
        $credentials = is_array($channel->credentials) ? $channel->credentials : [];
        if (trim($this->token) !== '') {
            $credentials['token'] = trim($this->token);
        }
        if ($channel->provider === 'whatsapp' || $creating && $this->provider === 'whatsapp') {
            $credentials['waba_id'] = trim($this->waba_id);
        }
        $channel->credentials = $credentials;
        $channel->save();

        session()->flash('status', $creating ? __('Canal creado.') : __('Canal actualizado.'));
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'display_name', 'external_id', 'token', 'waba_id', 'currentTokenMask']);
        $this->provider = 'whatsapp';
        $this->is_active = true;
    }

    /** El Embedded Signup está listo (flag + config Meta); si no, "Configuración pendiente". */
    public function signupReady(): bool
    {
        return app(\Modules\Social\Services\WhatsAppCoexistenceService::class)->isEnabled();
    }

    /**
     * Emite el state anti-CSRF del Embedded Signup (lo pide el JS AL PULSAR el botón;
     * nunca se genera en el navegador). Un solo uso, TTL corto, ligado a este usuario
     * y su institución (WhatsAppCoexistenceService::issueState). Devuelve null si el
     * flujo está apagado o el usuario no puede administrar canales.
     */
    public function signupState(): ?string
    {
        $coexistence = app(\Modules\Social\Services\WhatsAppCoexistenceService::class);
        $user = auth()->user();
        if (! $coexistence->isEnabled() || $user === null || ! $user->can('create', SocialChannel::class)) {
            return null;
        }

        $institutionId = app(\Modules\Core\Tenancy\CurrentInstitution::class)->id();
        if ($institutionId === null) {
            return null;
        }

        return $coexistence->issueState((int) $user->id, $institutionId);
    }

    /**
     * Datos PÚBLICOS que necesita el SDK de Facebook en el navegador (App ID y
     * Configuration ID no son secretos; el App Secret jamás sale del servidor).
     *
     * @return array{app_id: string, config_id: string, version: string}
     */
    public function signupConfig(): array
    {
        return [
            'app_id' => (string) config('social.meta_app_id', ''),
            'config_id' => (string) config('social.embedded_signup.config_id', ''),
            'version' => (string) config('social.graph_version', 'v26.0'),
        ];
    }

    public function render(): View
    {
        $channels = SocialChannel::query()->orderBy('provider')->orderBy('display_name')->get();

        $masks = $channels->mapWithKeys(function (SocialChannel $c): array {
            $token = (string) (data_get($c->credentials, 'token') ?? '');

            return [$c->id => $token !== '' ? SecretMasker::mask($token) : '—'];
        });

        $verify = (string) config('social.webhook_verify_token', '');
        $webhooks = [];
        foreach (SocialChannel::PROVIDERS as $key => $label) {
            $webhooks[] = [
                'provider' => $key,
                'label' => $label,
                'callback' => url('/api/social/webhook/'.$key),
                'verify' => $verify,
            ];
        }

        return view('social::channels', [
            'grouped' => $channels->groupBy('provider'),
            'masks' => $masks,
            'webhooks' => $webhooks,
        ]);
    }
}
