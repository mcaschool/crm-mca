<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Support\SecretMasker;
use Modules\Institutions\Models\Bot;
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

    // --- Asesor inteligente del canal (APAGADO por defecto) ------------------------------------

    public ?int $advisorChannelId = null;

    public bool $advisorEnabled = false;

    public string $advisorBotId = '';

    public int $advisorDelay = 0;

    /** true = atiende siempre; false = solo en el horario indicado. */
    public bool $advisorAlways = true;

    /** @var array<int, string> días ISO (1 = lunes … 7 = domingo) */
    public array $advisorDays = ['1', '2', '3', '4', '5'];

    public string $advisorFrom = '09:00';

    public string $advisorTo = '18:00';

    public string $advisorOffHoursMessage = '';

    public bool $advisorHandoff = true;

    public string $advisorHandoffMessage = '';

    public bool $advisorPauseOnHuman = true;

    public function editAdvisor(int $id): void
    {
        $channel = SocialChannel::query()->findOrFail($id);
        $this->authorize('update', $channel);

        $schedule = is_array($channel->advisor_schedule) ? $channel->advisor_schedule : null;
        $this->advisorChannelId = $channel->id;
        $this->advisorEnabled = (bool) $channel->advisor_enabled;
        $this->advisorBotId = $channel->advisor_bot_id !== null ? (string) $channel->advisor_bot_id : '';
        $this->advisorDelay = (int) $channel->advisor_reply_delay;
        $this->advisorAlways = $schedule === null;
        $this->advisorDays = $schedule !== null ? array_map('strval', (array) ($schedule['days'] ?? [])) : ['1', '2', '3', '4', '5'];
        $this->advisorFrom = (string) ($schedule['from'] ?? '09:00');
        $this->advisorTo = (string) ($schedule['to'] ?? '18:00');
        $this->advisorOffHoursMessage = (string) $channel->advisor_off_hours_message;
        $this->advisorHandoff = (bool) $channel->advisor_handoff_enabled;
        $this->advisorHandoffMessage = (string) $channel->advisor_handoff_message;
        $this->advisorPauseOnHuman = (bool) $channel->advisor_pause_on_human;
        $this->resetErrorBag();
    }

    public function saveAdvisor(): void
    {
        $channel = SocialChannel::query()->findOrFail((int) $this->advisorChannelId);
        $this->authorize('update', $channel);

        $this->validate([
            'advisorBotId' => [$this->advisorEnabled ? 'required' : 'nullable', 'nullable', 'integer'],
            'advisorDelay' => ['required', 'integer', Rule::in(SocialChannel::ADVISOR_DELAYS)],
            'advisorDays' => [$this->advisorAlways ? 'nullable' : 'required', 'array'],
            'advisorDays.*' => ['integer', 'between:1,7'],
            'advisorFrom' => ['required', 'date_format:H:i'],
            'advisorTo' => ['required', 'date_format:H:i'],
            'advisorOffHoursMessage' => ['nullable', 'string', 'max:1000'],
            'advisorHandoffMessage' => ['nullable', 'string', 'max:1000'],
        ], [
            'advisorBotId.required' => __('Elige qué asesor atiende este canal.'),
            'advisorDays.required' => __('Elige al menos un día.'),
        ]);

        // El asesor debe ser un asesor inteligente de ESTA institución (el scope lo garantiza).
        $botId = $this->advisorBotId !== '' ? (int) $this->advisorBotId : null;
        if ($botId !== null && ! Bot::query()->whereKey($botId)->where('type', '!=', 'human')->exists()) {
            $this->addError('advisorBotId', __('Ese asesor no está disponible.'));

            return;
        }

        $channel->advisor_enabled = $this->advisorEnabled;
        $channel->advisor_bot_id = $botId;
        $channel->advisor_reply_delay = $this->advisorDelay;
        $channel->advisor_schedule = $this->advisorAlways ? null : [
            'days' => array_values(array_map('intval', $this->advisorDays)),
            'from' => $this->advisorFrom,
            'to' => $this->advisorTo,
        ];
        $channel->advisor_off_hours_message = trim($this->advisorOffHoursMessage) !== '' ? trim($this->advisorOffHoursMessage) : null;
        $channel->advisor_handoff_enabled = $this->advisorHandoff;
        $channel->advisor_handoff_message = trim($this->advisorHandoffMessage) !== '' ? trim($this->advisorHandoffMessage) : null;
        $channel->advisor_pause_on_human = $this->advisorPauseOnHuman;
        $channel->save();

        session()->flash('status', $channel->advisor_enabled
            ? __('Asesor inteligente activado en :channel.', ['channel' => $channel->display_name])
            : __('Asesor inteligente desactivado en :channel.', ['channel' => $channel->display_name]));
        $this->cancelAdvisor();
    }

    public function cancelAdvisor(): void
    {
        $this->advisorChannelId = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $channels = SocialChannel::query()->with('advisorBot:id,assistant_name,status')->orderBy('provider')->orderBy('display_name')->get();

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
            'advisorBots' => $this->advisorChannelId !== null
                ? Bot::query()->where('type', '!=', 'human')->orderBy('assistant_name')->get(['id', 'assistant_name', 'status'])
                : collect(),
            'advisorChannel' => $this->advisorChannelId !== null ? $channels->firstWhere('id', $this->advisorChannelId) : null,
        ]);
    }
}
