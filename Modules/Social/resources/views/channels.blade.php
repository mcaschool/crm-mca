<div class="mca-page">
    @php
        $providers = \Modules\Social\Models\SocialChannel::PROVIDERS;
        $extLabels = ['whatsapp' => 'Phone Number ID', 'messenger' => 'Page ID', 'instagram' => 'IG User ID'];
    @endphp

    <x-ui.page-header class="ui-ph-stack" :title="__('Canales sociales')" :subtitle="__('Da de alta cada número de WhatsApp, Página de Facebook o cuenta de Instagram. Las credenciales se guardan cifradas; una vez guardado, el token solo se muestra enmascarado y puede reemplazarse.')">
        @can('create', \Modules\Social\Models\SocialChannel::class)
            <x-slot:actions>
                <x-ui.button :href="route('social.lead-forms')" icon="file-text">{{ __('Formularios publicitarios') }}</x-ui.button>
                {{-- «Conectar Meta»: enlace GET normal (SIN wire:navigate: navegar a la
                     pantalla Meta no debe pasar por Livewire). --}}
                <x-ui.button :href="route('social.meta')" icon="plug"
                   title="{{ __('Detecta tu Página de Facebook e Instagram con Facebook Login, sin datos técnicos.') }}">{{ __('Conectar Meta') }}</x-ui.button>
                {{-- Embedded Signup v4 (Coexistence): el SDK de Facebook SOLO se carga cuando
                     el feature flag está activo y existen App ID + Configuration ID; con el
                     flag apagado esta rama ni se renderiza (imposible conexión accidental). --}}
                @if ($this->signupReady())
                    @php($waSignupCfg = $this->signupConfig())
                    <span wire:ignore x-data="waEmbeddedSignup()" class="ui-actions-row">
                        <script>
                            window.waEmbeddedSignup = function () {
                                return {
                                    cfg: @js($waSignupCfg),
                                    endpoint: @js(route('social.wa-signup')),
                                    csrf: @js(csrf_token()),
                                    labels: @js([
                                        'idle' => __('Conectar WhatsApp Business'),
                                        'starting' => __('Iniciando…'),
                                        'connecting' => __('Conectando…'),
                                        'connected' => __('Conectado'),
                                        'cancelled' => __('Cancelado'),
                                        'error' => __('Error'),
                                    ]),
                                    status: 'idle',
                                    message: '',
                                    busy: false,
                                    submitted: false,
                                    state: null, code: null, wabaId: null, phoneId: null,

                                    init() {
                                        // SDK solo aquí (la rama existe únicamente con el flag activo).
                                        if (! document.getElementById('facebook-jssdk')) {
                                            const s = document.createElement('script');
                                            s.id = 'facebook-jssdk';
                                            s.src = 'https://connect.facebook.net/en_US/sdk.js';
                                            s.async = true; s.defer = true; s.crossOrigin = 'anonymous';
                                            document.body.appendChild(s);
                                        }
                                        const cfg = this.cfg;
                                        window.fbAsyncInit = () => {
                                            FB.init({ appId: cfg.app_id, autoLogAppEvents: false, xfbml: false, version: cfg.version });
                                        };
                                        // Evento v4 del Embedded Signup: llega por window message desde facebook.com.
                                        window.addEventListener('message', (event) => {
                                            try {
                                                const origin = new URL(event.origin).hostname;
                                                if (! origin.endsWith('facebook.com')) return;
                                                const data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                                                if (! data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
                                                if (data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' || data.event === 'FINISH') {
                                                    this.wabaId = data.data?.waba_id ?? null;
                                                    this.phoneId = data.data?.phone_number_id ?? null;
                                                    this.trySubmit();
                                                } else if (data.event === 'CANCEL') {
                                                    if (this.status !== 'connected') { this.status = 'cancelled'; this.busy = false; }
                                                } else if (data.event === 'ERROR') {
                                                    this.fail(@js(__('La conexión con Meta devolvió un error. Inténtalo de nuevo.')));
                                                }
                                                // Nunca se loguea el payload ni el code.
                                            } catch (e) { /* mensajes ajenos: ignorar */ }
                                        });
                                    },

                                    async start() {
                                        if (this.busy || this.status === 'connected' || typeof FB === 'undefined') return;
                                        this.busy = true; this.submitted = false; this.message = '';
                                        this.code = null; this.wabaId = null; this.phoneId = null;
                                        this.status = 'starting';
                                        // El state SIEMPRE lo emite el backend (one-time, ligado a usuario+institución).
                                        this.state = await this.$wire.signupState();
                                        if (! this.state) { this.fail(@js(__('No se pudo iniciar la conexión. Recarga la página.'))); return; }
                                        this.status = 'connecting';
                                        FB.login((response) => {
                                            const code = response?.authResponse?.code;
                                            if (code) { this.code = code; this.trySubmit(); }
                                            else if (this.status !== 'connected' && ! this.submitted) { this.status = 'cancelled'; this.busy = false; }
                                        }, {
                                            config_id: this.cfg.config_id,
                                            response_type: 'code',
                                            override_default_response_type: true,
                                            extras: { featureType: 'whatsapp_business_app_onboarding' },
                                        });
                                    },

                                    async trySubmit() {
                                        if (this.submitted || ! this.state || ! this.code || ! this.wabaId || ! this.phoneId) return;
                                        this.submitted = true; // anti doble-submit/doble onboarding
                                        try {
                                            const res = await fetch(this.endpoint, {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                                                body: JSON.stringify({ state: this.state, code: this.code, waba_id: this.wabaId, phone_number_id: this.phoneId }),
                                            });
                                            const body = await res.json();
                                            this.code = null; this.state = null; // el code no se conserva en memoria
                                            if (res.ok && body.status === 'connected') {
                                                this.status = 'connected'; this.busy = false;
                                                this.$wire.$refresh(); // la lista muestra el canal y su connection_status real
                                            } else {
                                                this.fail(body.message || @js(__('No se pudo completar la conexión. Inténtalo de nuevo.')));
                                            }
                                        } catch (e) {
                                            this.fail(@js(__('No se pudo completar la conexión. Inténtalo de nuevo.')));
                                        }
                                    },

                                    fail(message) { this.status = 'error'; this.message = message; this.busy = false; this.submitted = false; },
                                };
                            };
                        </script>
                        <button type="button" class="ui-btn ui-btn--secondary" x-on:click="start()" x-bind:disabled="busy"
                                title="{{ __('Inicia la conexión del WhatsApp Business App (Coexistence).') }}">
                            <x-ui.icon name="plug" />
                            <span x-text="labels[status] ?? labels.idle">{{ __('Conectar WhatsApp Business') }}</span>
                        </button>
                        <span x-show="message !== ''" x-text="message" class="ui-error" role="alert"></span>
                    </span>
                @else
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--wrap" disabled
                            title="{{ __('Configuración pendiente: falta habilitar el Embedded Signup de Meta (SOCIAL_WA_SIGNUP_ENABLED y SOCIAL_WA_SIGNUP_CONFIG_ID).') }}">
                        <x-ui.icon name="plug" />
                        <span>{{ __('Conectar WhatsApp Business') }} · {{ __('Configuración pendiente') }}</span>
                    </button>
                @endif
                <x-ui.button variant="primary" icon="plus" wire:click="create">{{ __('Nuevo canal') }}</x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    <div class="ui-stack">
        @if (session('status'))
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <div class="ui-callout ui-tone--warning">
            <x-ui.icon name="lock" />
            <span>{{ __('Usa el TOKEN PERMANENTE (System User), no el temporal: los tokens temporales caducan y romperían el canal.') }}</span>
        </div>

        {{-- ---------------- ASESOR INTELIGENTE DEL CANAL (apagado por defecto) ---------------- --}}
        @if ($advisorChannel)
            <section class="ui-card" data-testid="advisor-panel" aria-labelledby="adv-title">
                <div class="ui-card-h"><x-ui.icon name="bot" class="ui-card-ic" /><h2 id="adv-title">{{ __('Asesor inteligente · :channel', ['channel' => $advisorChannel->display_name]) }}</h2></div>
                <form wire:submit="saveAdvisor" novalidate>
                    <div class="ui-card-p ui-stack">
                        <p class="ui-help">{{ __('Responde automáticamente los mensajes de este canal con el mismo asesor de la web. Una persona del equipo puede tomar la conversación en cualquier momento.') }}</p>

                        <div class="ui-field">
                            <span class="ui-label" id="adv-on-l">{{ __('Asesor inteligente') }}</span>
                            <div class="ui-seg" role="group" aria-labelledby="adv-on-l">
                                <button type="button" wire:click="$set('advisorEnabled', false)" aria-pressed="{{ $advisorEnabled ? 'false' : 'true' }}">{{ __('Desactivado') }}</button>
                                <button type="button" wire:click="$set('advisorEnabled', true)" aria-pressed="{{ $advisorEnabled ? 'true' : 'false' }}">{{ __('Activado') }}</button>
                            </div>
                            @error('advisorEnabled') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            <p class="ui-help" data-testid="advisor-dispatch">
                                @if ($advisorPending)
                                    {{ $advisorPending }}
                                @else
                                    {{ __('Procesamiento automático del servidor: funcionando (última comprobación :when).', ['when' => $advisorLastSeen?->diffForHumans()]) }}
                                @endif
                            </p>
                        </div>

                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Asesor asignado')" for="adv-bot" :error="$errors->first('advisorBotId')">
                                <select id="adv-bot" wire:model="advisorBotId" @class(['ui-select', 'is-invalid' => $errors->has('advisorBotId')]) @error('advisorBotId') aria-describedby="adv-bot-error" @enderror>
                                    <option value="">{{ __('— Elegir —') }}</option>
                                    @foreach ($advisorBots as $b)
                                        <option value="{{ $b->id }}">{{ $b->assistant_name }}{{ $b->status !== 'active' ? ' ('.__('inactivo').')' : '' }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('Espera antes de responder')" for="adv-delay">
                                <select id="adv-delay" wire:model="advisorDelay" class="ui-select">
                                    @foreach (\Modules\Social\Models\SocialChannel::ADVISOR_DELAYS as $d)
                                        <option value="{{ $d }}">{{ $d === 0 ? __('Responder al momento') : __(':n segundos', ['n' => $d]) }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        </div>

                        <div class="ui-field">
                            <span class="ui-label" id="adv-hours-l">{{ __('Horario de atención automática') }}</span>
                            <div class="ui-seg" role="group" aria-labelledby="adv-hours-l">
                                <button type="button" wire:click="$set('advisorAlways', true)" aria-pressed="{{ $advisorAlways ? 'true' : 'false' }}">{{ __('Siempre') }}</button>
                                <button type="button" wire:click="$set('advisorAlways', false)" aria-pressed="{{ $advisorAlways ? 'false' : 'true' }}">{{ __('Solo en un horario') }}</button>
                            </div>
                        </div>
                        @unless ($advisorAlways)
                                <div class="ui-field">
                                    <span class="ui-label" id="adv-days-l">{{ __('Días') }}</span>
                                    <div class="ui-checks" role="group" aria-labelledby="adv-days-l">
                                        @foreach ([1 => __('Lun'), 2 => __('Mar'), 3 => __('Mié'), 4 => __('Jue'), 5 => __('Vie'), 6 => __('Sáb'), 7 => __('Dom')] as $num => $day)
                                            <label class="ui-check"><input type="checkbox" wire:model="advisorDays" value="{{ $num }}"> {{ $day }}</label>
                                        @endforeach
                                    </div>
                                    @error('advisorDays') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                                </div>
                                <div class="ui-field">
                                    <span class="ui-label" id="adv-time-l">{{ __('Horario') }}</span>
                                    <div class="ui-interval" role="group" aria-labelledby="adv-time-l">
                                        <span class="sep">{{ __('de') }}</span>
                                        <input type="time" wire:model="advisorFrom" class="ui-input" aria-label="{{ __('Desde') }}">
                                        <span class="sep">{{ __('a') }}</span>
                                        <input type="time" wire:model="advisorTo" class="ui-input" aria-label="{{ __('Hasta') }}">
                                    </div>
                                </div>
                                <x-ui.field :label="__('Mensaje fuera de horario')" for="adv-off">
                                    <textarea id="adv-off" wire:model="advisorOffHoursMessage" rows="2" maxlength="1000" class="ui-input ui-textarea" placeholder="{{ __('Ej.: Gracias por escribirnos. Te responderemos en nuestro horario de atención.') }}"></textarea>
                                </x-ui.field>
                        @endunless

                        <div class="ui-field">
                            <span class="ui-label">{{ __('Transferir a una persona') }}</span>
                            <label class="ui-check"><input type="checkbox" wire:model.live="advisorHandoff"> {{ __('Si la persona pide hablar con alguien del equipo, dejar de responder y avisar al equipo.') }}</label>
                            @if ($advisorHandoff)
                                <textarea wire:model="advisorHandoffMessage" rows="2" maxlength="1000" class="ui-input ui-textarea" aria-label="{{ __('Transferir a una persona') }}" placeholder="{{ __('Te pongo en contacto con una persona del equipo. Te responderá por aquí en cuanto esté disponible.') }}"></textarea>
                            @endif
                        </div>

                        <p class="ui-help">{{ __('Cuando una persona del equipo responde en una conversación, el asesor deja de responder en ella hasta que alguien lo reactive desde la bandeja.') }}</p>
                    </div>
                    <div class="ui-card-f ui-card-f--end">
                        <x-ui.button variant="ghost" wire:click="cancelAdvisor">{{ __('Cancelar') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" icon="check">{{ __('Guardar') }}</x-ui.button>
                    </div>
                </form>
            </section>
        @endif

        {{-- ---------------- FORMULARIO (alta / edición) ---------------- --}}
        @if ($showForm)
            <form class="ui-card" wire:submit="save" novalidate>
                <div class="ui-card-h"><x-ui.icon name="{{ $editingId ? 'pencil' : 'plus' }}" class="ui-card-ic" /><h2>{{ $editingId ? __('Editar canal') : __('Nuevo canal') }}</h2></div>
                <div class="ui-card-p">
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Proveedor')" for="ch-provider" :error="$errors->first('provider')" :help="$editingId ? __('El proveedor no se cambia al editar.') : null">
                            <select id="ch-provider" wire:model.live="provider" @disabled($editingId) @class(['ui-select', 'is-invalid' => $errors->has('provider')]) @error('provider') aria-describedby="ch-provider-error" @enderror>
                                @foreach ($providers as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <x-ui.field :label="__('Nombre para distinguirlo')" for="ch-name" :error="$errors->first('display_name')">
                            <input id="ch-name" type="text" wire:model="display_name" placeholder="{{ __('Ej. Admisiones · Línea 1') }}" @class(['ui-input', 'is-invalid' => $errors->has('display_name')]) @error('display_name') aria-describedby="ch-name-error" @enderror>
                        </x-ui.field>

                        <x-ui.field :label="$extLabels[$provider] ?? __('Identificador externo')" for="ch-ext" :error="$errors->first('external_id')">
                            <input id="ch-ext" type="text" wire:model="external_id" autocomplete="off" @class(['ui-input', 'ui-mono', 'is-invalid' => $errors->has('external_id')]) @error('external_id') aria-describedby="ch-ext-error" @enderror>
                        </x-ui.field>

                        <div class="ui-field">
                            <label class="ui-label" for="ch-token">{{ __('Token de acceso') }}</label>
                            @if ($currentTokenMask)
                                <p class="ui-help">
                                    {{ __('Actual') }}: <span class="ui-mono">{{ $currentTokenMask }}</span>
                                    — {{ __('deja el campo vacío para conservarlo, o escribe uno nuevo para reemplazarlo.') }}
                                </p>
                            @endif
                            <input id="ch-token" type="password" wire:model="token" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('token')]) @error('token') aria-describedby="ch-token-error" @enderror
                                   placeholder="{{ $editingId ? __('Reemplazar token') : __('Pega el token permanente') }}">
                            @error('token') <p class="ui-error" id="ch-token-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        </div>

                        @if ($provider === 'whatsapp')
                            <x-ui.field label="WABA ID" for="ch-waba" :error="$errors->first('waba_id')" :help="__('Necesario para gestionar plantillas. Se guarda cifrado junto al token.')">
                                <input id="ch-waba" type="text" wire:model="waba_id" autocomplete="off" placeholder="{{ __('ID de la cuenta de WhatsApp Business (para plantillas)') }}" @class(['ui-input', 'is-invalid' => $errors->has('waba_id')]) @error('waba_id') aria-describedby="ch-waba-error" @enderror>
                            </x-ui.field>
                        @endif

                        <div class="ui-span-full">
                            <label class="ui-check"><input type="checkbox" wire:model="is_active"> {{ __('Canal activo') }}</label>
                        </div>
                    </div>
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="check">{{ __('Guardar') }}</x-ui.button>
                </div>
            </form>
        @endif

        {{-- ---------------- LISTA (fichas lado a lado) ---------------- --}}
        <div class="ui-grid">
            @forelse ($grouped as $providerKey => $list)
                @foreach ($list as $c)
                    <article class="ui-card ui-entity" wire:key="ch-{{ $c->id }}">
                        <div class="ui-entity-h">
                            <span class="ui-brand-ic">@include('social::partials.provider-icon', ['provider' => $providerKey, 'size' => 20])</span>
                            <div class="ui-person-text">
                                <h3 class="ui-entity-title">{{ $c->display_name }}</h3>
                                <span class="ui-cell-sub">{{ $providers[$providerKey] ?? $providerKey }}</span>
                            </div>
                            <x-ui.badge :tone="$c->is_active ? 'success' : 'neutral'" dot>{{ $c->is_active ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                        </div>
                        <div class="ui-entity-b ui-stack">
                            <dl class="ui-meta">
                                <dt>{{ $extLabels[$providerKey] ?? __('ID') }}</dt>
                                <dd>@if ($c->external_id)<span class="ui-mono">{{ $c->external_id }}</span>@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Token') }}</dt>
                                <dd><span class="ui-mono">{{ $masks[$c->id] }}</span></dd>
                            </dl>
                            <div class="ui-person-line">
                                @if ($providerKey === 'whatsapp')
                                    <x-ui.badge :tone="$c->connection_status === 'offboarded' ? 'danger' : 'success'" dot>{{ __($c->connectionLabel()) }}</x-ui.badge>
                                @endif
                                {{-- Asesor inteligente del canal (apagado por defecto) --}}
                                @if ($c->advisor_enabled && $c->advisorBot)
                                    <x-ui.badge tone="blue" icon="bot" data-testid="advisor-badge-{{ $c->id }}">{{ __('Asesor inteligente: :name', ['name' => $c->advisorBot->assistant_name]) }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="neutral" icon="bot" data-testid="advisor-badge-{{ $c->id }}">{{ __('Asesor inteligente desactivado') }}</x-ui.badge>
                                @endif
                            </div>
                            @if ($providerKey === 'whatsapp' && $c->connection_status === 'offboarded')
                                <div class="ui-callout ui-callout--sm ui-tone--danger" role="status">
                                    <x-ui.icon name="alert-triangle" />
                                    <span>{{ __('El teléfono se desconectó de la API: los envíos están bloqueados hasta reconectar. Las credenciales se conservan.') }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="ui-card-f">
                            <x-ui.button size="sm" wire:click="edit({{ $c->id }})">{{ __('Editar') }}</x-ui.button>
                            <x-ui.button size="sm" wire:click="editAdvisor({{ $c->id }})">{{ __('Asesor inteligente') }}</x-ui.button>
                            <span class="sp"></span>
                            <x-ui.button size="sm" variant="ghost" wire:click="toggle({{ $c->id }})"
                                wire:confirm="{{ $c->is_active ? __('¿Desactivar el canal «:name»? Dejará de usarse para atender y enviar mensajes hasta que se vuelva a activar.', ['name' => $c->display_name]) : __('¿Activar el canal «:name»?', ['name' => $c->display_name]) }}">{{ $c->is_active ? __('Desactivar') : __('Activar') }}</x-ui.button>
                        </div>
                    </article>
                @endforeach
            @empty
                <div class="ui-card ui-empty ui-span-full">
                    <div class="ui-empty-ic"><x-ui.icon name="message-circle" /></div>
                    {{ __('Aún no hay canales configurados. Crea el primero con «Nuevo canal».') }}
                </div>
            @endforelse
        </div>

        {{-- ---------------- AYUDA DE WEBHOOK ---------------- --}}
        <section class="ui-card" aria-labelledby="wh-title">
            <div class="ui-card-h">
                <x-ui.icon name="globe" class="ui-card-ic" />
                <div class="ui-split-main">
                    <h2 id="wh-title">{{ __('Webhook — pégalo en Meta') }}</h2>
                    <span class="ui-cell-sub">{{ __('Callback URL y Verify Token que se configuran en el panel de Meta al suscribir cada webhook. Solo lectura.') }}</span>
                </div>
            </div>
            <div class="ui-vr-grid ui-vr-grid--3">
                @foreach ($webhooks as $wh)
                    <div class="ui-vr" wire:key="wh-{{ $wh['provider'] }}">
                        <h3 class="ui-vr-title ui-person-line">@include('social::partials.provider-icon', ['provider' => $wh['provider'], 'size' => 18]) {{ $wh['label'] }}</h3>

                        <div class="ui-field">
                            <span class="ui-label">{{ __('Callback URL') }}</span>
                            <div class="ui-copy">
                                <span class="ui-copy-text">{{ $wh['callback'] }}</span>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-data="{c:false}"
                                        @click="navigator.clipboard.writeText($el.dataset.copy); c=true; setTimeout(()=>c=false,1200)"
                                        data-copy="{{ $wh['callback'] }}"
                                        x-text="c ? '{{ __('¡Copiado!') }}' : '{{ __('Copiar') }}'">{{ __('Copiar') }}</button>
                            </div>
                        </div>

                        <div class="ui-field">
                            <span class="ui-label">{{ __('Verify Token') }}</span>
                            <div class="ui-copy">
                                <span class="ui-copy-text">{{ $wh['verify'] ?: __('(define SOCIAL_WEBHOOK_VERIFY_TOKEN)') }}</span>
                                @if ($wh['verify'])
                                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-data="{c:false}"
                                            @click="navigator.clipboard.writeText($el.dataset.copy); c=true; setTimeout(()=>c=false,1200)"
                                            data-copy="{{ $wh['verify'] }}"
                                            x-text="c ? '{{ __('¡Copiado!') }}' : '{{ __('Copiar') }}'">{{ __('Copiar') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
