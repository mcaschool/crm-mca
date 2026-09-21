<div>
    <x-ui.styles />

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">Meta</h1>
                <p class="mca-sub">{{ __('Facebook · Messenger · Instagram') }}</p>
            </div>
            <div class="sp" style="flex:1"></div>
            <a href="{{ route('social.channels') }}" class="btn btn-soft btn-sm">
                <x-ui.icon name="plug" class="ic" style="width:15px;height:15px" /> {{ __('Canales') }}
            </a>
        </div>

        @if (! $this->platformReady())
            {{-- Plataforma Meta sin configurar: mensaje amigable, distinto por tipo de usuario.
                 Nunca se muestran detalles técnicos a un administrador de institución. --}}
            <div class="card card-p" style="display:flex;align-items:center;gap:12px;background:var(--mca-page-bg,#F4F6F9);margin-top:16px">
                <x-ui.icon name="plug" style="width:20px;height:20px;color:var(--muted)" />
                @if (auth()->user()?->isSuperAdmin())
                    <span style="font-size:13.5px;color:var(--ink-2,#3B4453)">{{ __('Configuración de plataforma Meta pendiente.') }}</span>
                @else
                    <span style="font-size:13.5px;color:var(--ink-2,#3B4453)">{{ __('La integración con Meta no está disponible temporalmente.') }}</span>
                @endif
            </div>
        @else
            {{-- ============================ PASO: INICIO ============================ --}}
            @if ($step === 'idle')
                <div class="card card-p" style="margin-top:16px;display:flex;flex-direction:column;gap:14px;align-items:flex-start">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span class="badge badge-off">{{ __('No conectado') }}</span>
                        </div>
                        <p class="mca-sub" style="margin-top:10px;max-width:520px">
                            {{ __('Conecta tu cuenta de Meta para detectar automáticamente tu Página de Facebook (Messenger) y tu cuenta de Instagram profesional. No tienes que buscar identificadores ni pegar tokens.') }}
                        </p>
                    </div>

                    @can('create', \Modules\Social\Models\SocialChannel::class)
                        @php($metaCfg = $this->browserConfig())
                        <span wire:ignore x-data="metaConnect()" style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <script>
                                window.metaConnect = function () {
                                    return {
                                        cfg: @js($metaCfg),
                                        labels: @js([
                                            'idle' => __('Conectar Meta'),
                                            'starting' => __('Iniciando…'),
                                            'connecting' => __('Continuar con Facebook'),
                                            'discovering' => __('Detectando activos…'),
                                            'cancelled' => __('Cancelado'),
                                        ]),
                                        status: 'idle',
                                        busy: false,
                                        state: null,

                                        init() {
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
                                        },

                                        async start() {
                                            if (this.busy || typeof FB === 'undefined') return;
                                            this.busy = true; this.status = 'starting';
                                            // El state SIEMPRE lo emite el backend (un solo uso, usuario+institución).
                                            this.state = await this.$wire.connectState();
                                            if (! this.state) { this.status = 'idle'; this.busy = false; return; }
                                            this.status = 'connecting';
                                            // El tipo lo fija la plataforma (config), NO se adivina: Meta exige los
                                            // parámetros correctos ANTES del diálogo (un System User con response_type
                                            // incorrecto es causa conocida de fallo).
                                            //   user   → FB.login(cb, { config_id })
                                            //   system → FB.login(cb, { config_id, response_type:'code', override_default_response_type:true })
                                            const opts = { config_id: this.cfg.config_id };
                                            if (this.cfg.token_type === 'system') {
                                                opts.response_type = 'code';
                                                opts.override_default_response_type = true;
                                            }
                                            FB.login((response) => {
                                                const auth = response?.authResponse;
                                                const code = auth?.code;
                                                const token = auth?.accessToken;
                                                if (code) { this.finish(code, null); }
                                                else if (token) { this.finish(null, token); }
                                                else { this.status = 'idle'; this.busy = false; }
                                            }, opts);
                                        },

                                        // El code/token se envía de inmediato al backend por HTTPS; jamás se guarda en
                                        // this.*, localStorage/sessionStorage ni logs. El backend infiere el tipo.
                                        async finish(code, token) {
                                            this.status = 'discovering';
                                            await this.$wire.discover(this.state, code || '', token || '');
                                            this.state = null; this.busy = false; this.status = 'idle';
                                        },
                                    };
                                };
                            </script>
                            <button type="button" class="btn btn-primary" x-on:click="start()" x-bind:disabled="busy">
                                <x-ui.icon name="plug" class="ic" style="width:15px;height:15px" />
                                <span x-text="labels[status] ?? labels.idle">{{ __('Conectar Meta') }}</span>
                            </button>
                        </span>
                    @endcan

                    @if ($errorMessage !== '')
                        <span style="font-size:12.5px;color:#8A1C1C">{{ $errorMessage }}</span>
                    @endif
                </div>
            @endif

            {{-- ============================ PASO: SELECCIÓN ============================ --}}
            @if ($step === 'select')
                <div style="margin-top:18px">
                    <h2 style="font-size:15px;font-weight:700;margin:0 0 4px">{{ __('Selecciona la cuenta que deseas conectar') }}</h2>
                    <p class="mca-sub" style="margin-top:0">{{ __('Esto es lo que detectamos con tu autorización de Meta.') }}</p>

                    @if ($pages === [])
                        <div class="card card-p" style="margin-top:12px">
                            <p style="font-size:13.5px;color:var(--muted);margin:0">
                                {{ __('No encontramos Páginas de Facebook en esta cuenta. Asegúrate de administrar al menos una Página y de haber autorizado el acceso.') }}
                            </p>
                        </div>
                    @else
                        <div class="mca-grid" style="margin-top:12px">
                            @foreach ($pages as $page)
                                <button type="button" wire:key="pg-{{ $page['page_id'] }}" wire:click="select('{{ $page['page_id'] }}')"
                                        class="card card-p" style="text-align:left;cursor:pointer;border-width:2px;{{ $selectedPageId === $page['page_id'] ? 'border-color:var(--mca-blue,#1E5AA8)' : '' }}">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                                        <h3 style="font-size:14.5px;font-weight:700;margin:0">{{ $page['name'] }}</h3>
                                        @if ($selectedPageId === $page['page_id'])
                                            <span class="badge badge-on">{{ __('Seleccionada') }}</span>
                                        @endif
                                    </div>

                                    <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                                        <div style="display:flex;align-items:center;gap:8px;font-size:13px">
                                            @include('social::partials.provider-icon', ['provider' => 'messenger', 'size' => 16])
                                            <span style="font-weight:600">Facebook / Messenger</span>
                                            <span class="badge badge-on">{{ __('Detectado') }}</span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;font-size:13px">
                                            @include('social::partials.provider-icon', ['provider' => 'instagram', 'size' => 16])
                                            <span style="font-weight:600">Instagram</span>
                                            @if ($page['has_instagram'])
                                                <span style="color:var(--ink-2,#3B4453)">{{ $page['instagram_username'] ? '@'.$page['instagram_username'] : '' }}</span>
                                                <span class="badge badge-on">{{ __('Detectado') }}</span>
                                            @else
                                                <span class="badge badge-off">{{ __('No detectado') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </div>

                        <div style="margin-top:18px;display:flex;align-items:center;gap:12px">
                            <button type="button" wire:click="confirm" class="btn btn-primary" @disabled($selectedPageId === null)>{{ __('Continuar') }}</button>
                            <button type="button" wire:click="restart" class="btn btn-ghost">{{ __('Empezar de nuevo') }}</button>
                        </div>
                    @endif

                    @if ($errorMessage !== '')
                        <p style="font-size:12.5px;color:#8A1C1C;margin-top:10px">{{ $errorMessage }}</p>
                    @endif
                </div>
            @endif

            {{-- ============================ PASO: HECHO ============================ --}}
            @if ($step === 'done')
                <div class="card card-p" style="margin-top:18px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <x-ui.icon name="check" style="width:20px;height:20px;color:var(--mca-ok,#2E7D32)" />
                        <h2 style="font-size:15px;font-weight:700;margin:0">{{ __('Activos detectados correctamente') }}</h2>
                    </div>
                    @if ($selectedPage)
                        <p class="mca-sub" style="margin-top:10px">
                            {{ __('Cuenta') }}: <strong>{{ $selectedPage['name'] }}</strong> — Facebook / Messenger{{ $selectedPage['has_instagram'] ? ' · Instagram '.($selectedPage['instagram_username'] ? '@'.$selectedPage['instagram_username'] : '') : '' }}
                        </p>
                    @endif
                    <p class="mca-sub" style="margin-top:6px">
                        {{ __('Por ahora esto es solo una comprobación: no se creó ni modificó ningún canal, y tu conexión de Meta actual sigue intacta.') }}
                    </p>
                    <div style="margin-top:14px">
                        <button type="button" wire:click="restart" class="btn btn-soft btn-sm">{{ __('Volver a empezar') }}</button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
