{{-- Botón «Conectar Meta» (Facebook Login for Business). Compartido por «Conectar Meta» y el asistente de
     Formularios publicitarios. Parámetros: $cfg (browserConfig), $action (método Livewire que recibe
     state + code/token), $idleLabel, $btnClass. El state lo emite SIEMPRE el backend (connectState). --}}
@php($metaLabels = [
    'idle' => $idleLabel,
    'starting' => __('Iniciando…'),
    'connecting' => __('Continuar con Facebook'),
    'discovering' => __('Guardando la conexión…'),
    'cancelled' => __('Cancelado'),
])
{{-- Configuración, textos y acción viajan como ARGUMENTOS: si Livewire reemplaza el botón (p. ej. al
     conectar o desconectar), el nuevo los trae actualizados aunque el script no vuelva a ejecutarse. --}}
<span wire:ignore x-data="metaConnect(@js($cfg), @js($metaLabels), @js($action))" style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap">
    <script>
        window.metaConnect = function (cfg, labels, action) {
            return {
                cfg: cfg,
                labels: labels,
                action: action,
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
                    await this.$wire.call(this.action, this.state, code || '', token || '');
                    this.state = null; this.busy = false; this.status = 'idle';
                },
            };
        };
    </script>
    <button type="button" class="{{ $btnClass ?? 'btn btn-primary' }}" x-on:click="start()" x-bind:disabled="busy">
        <x-ui.icon name="plug" class="ic" style="width:15px;height:15px" />
        <span x-text="labels[status] ?? labels.idle">{{ $idleLabel }}</span>
    </button>
</span>
