{{--
  TeamCalls · interfaz de llamadas REUTILIZABLE (fase 3D). Autocontenida: estilos, SDK oficial de LiveKit,
  núcleo y lógica se cargan desde el módulo; no usa componentes ni el layout del CRM, para poder
  integrarse después en otras aplicaciones de MCA One con la identidad y autorización compartidas.
  Necesita: $config y $assets (CallsUiController::viewData) y una meta «csrf-token» en la página.
  Sin secretos ni salas: solo rutas de la API (con el id de la llamada como plantilla) y textos.
--}}
<link rel="stylesheet" href="{{ $assets['teamcalls-ui.css'] }}">
<div class="tc-root" id="tc-root">
    <p class="tc-notice" role="note">{{ __('teamcalls.ui.dev_notice') }}</p>
    <div class="tc-alert" id="tc-alert" role="alert" hidden>
        <span id="tc-alert-text"></span>
        <button type="button" class="tc-alert-x" id="tc-alert-close" aria-label="×">×</button>
    </div>

    <div class="tc-grid">
        <aside class="tc-side">
            <section class="tc-card">
                <h2 class="tc-h2">{{ __('teamcalls.ui.contacts') }}</h2>
                <input type="search" id="tc-search" class="tc-input" placeholder="{{ __('teamcalls.ui.search') }}" autocomplete="off">
                <ul class="tc-list" id="tc-contacts" aria-live="polite"><li class="tc-muted">{{ __('teamcalls.ui.loading') }}</li></ul>
            </section>
            <section class="tc-card">
                <h2 class="tc-h2">{{ __('teamcalls.ui.history') }}</h2>
                <ul class="tc-list" id="tc-history"><li class="tc-muted">{{ __('teamcalls.ui.loading') }}</li></ul>
            </section>
        </aside>

        <section class="tc-card tc-stage" id="tc-stage" aria-live="polite">
            <div class="tc-stage-head">
                <span class="tc-status" id="tc-status" data-phase="idle"></span>
                <span class="tc-peer" id="tc-peer"></span>
                <span class="tc-synthetic" id="tc-synthetic" hidden></span>
                <button type="button" class="tc-ico-btn tc-head-btn" id="tc-fullscreen" hidden></button>
            </div>
            <div class="tc-toasts" id="tc-toasts" role="status" aria-live="polite"></div>
            <div class="tc-elsewhere" id="tc-elsewhere" role="status" hidden>
                <span>{{ __('teamcalls.ui.elsewhere') }}</span>
                <span class="tc-elsewhere-acts">
                    <button type="button" class="tc-ctl tc-ctl--ok" id="tc-takeover">{{ __('teamcalls.ui.take_over') }}</button>
                    <button type="button" class="tc-ctl tc-ctl--danger" id="tc-elsewhere-hangup">{{ __('teamcalls.ui.hangup') }}</button>
                </span>
            </div>
            <div class="tc-empty" id="tc-empty">
                <svg class="tc-empty-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>
                <p>{{ __('teamcalls.ui.stage_empty') }}</p>
            </div>
            {{-- Sala (fase 4F): pantalla compartida como contenido principal y mosaico adaptable de participantes. --}}
            <div class="tc-room" id="tc-room" data-mode="grid" hidden>
                <div class="tc-focus" id="tc-focus"></div>
                <div class="tc-tiles" id="tc-tiles"></div>
            </div>
            <div class="tc-controls" id="tc-controls" hidden>
                <button type="button" class="tc-ctl" id="tc-mic" aria-pressed="true"></button>
                <button type="button" class="tc-ctl" id="tc-cam" aria-pressed="true"></button>
                <button type="button" class="tc-ctl" id="tc-screen" aria-pressed="false" hidden></button>
                <button type="button" class="tc-ctl" id="tc-people-btn"></button>
                <button type="button" class="tc-ctl tc-ctl--danger" id="tc-hangup"></button>
            </div>
            <div class="tc-bottom" id="tc-bottom" hidden>
                <details class="tc-details" id="tc-people" open>
                    <summary>{{ __('teamcalls.ui.participants') }} <span class="tc-count" id="tc-people-count"></span></summary>
                    <ul class="tc-list" id="tc-participants"></ul>
                    <div class="tc-invite">
                        <button type="button" class="tc-ctl tc-ctl--sm" id="tc-invite-open">{{ __('teamcalls.ui.invite') }}</button>
                        <div class="tc-invite-panel" id="tc-invite-panel" hidden>
                            <input type="search" id="tc-invite-search" class="tc-input" placeholder="{{ __('teamcalls.ui.search') }}" autocomplete="off">
                            <ul class="tc-list tc-invite-list" id="tc-invite-list"></ul>
                            <div class="tc-invite-acts">
                                <button type="button" class="tc-ctl tc-ctl--sm" id="tc-invite-cancel">{{ __('teamcalls.ui.cancel') }}</button>
                                <button type="button" class="tc-ctl tc-ctl--sm tc-ctl--ok" id="tc-invite-send" disabled>{{ __('teamcalls.ui.invite_send') }}</button>
                            </div>
                        </div>
                    </div>
                </details>
                <details class="tc-details">
                    <summary>{{ __('teamcalls.ui.devices') }}</summary>
                    <label class="tc-field">{{ __('teamcalls.ui.microphone') }}<select id="tc-dev-audioinput" class="tc-input"></select></label>
                    <label class="tc-field">{{ __('teamcalls.ui.camera') }}<select id="tc-dev-videoinput" class="tc-input"></select></label>
                    <label class="tc-field" id="tc-dev-audiooutput-wrap">{{ __('teamcalls.ui.speaker') }}<select id="tc-dev-audiooutput" class="tc-input"></select></label>
                </details>
            </div>
        </section>
    </div>

    <div class="tc-modal" id="tc-incoming" role="dialog" aria-modal="true" aria-labelledby="tc-incoming-title" hidden>
        <div class="tc-modal-card">
            <div class="tc-ring" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg></div>
            <h2 id="tc-incoming-title"></h2>
            <p id="tc-incoming-kind" class="tc-muted"></p>
            <div class="tc-modal-acts">
                <button type="button" class="tc-ctl tc-ctl--danger" id="tc-decline"></button>
                <button type="button" class="tc-ctl tc-ctl--ok" id="tc-accept"></button>
            </div>
        </div>
    </div>

    {{-- Confirmación de una intención que no viene de esta aplicación (fase 4E: CallLaunch). --}}
    <div class="tc-modal" id="tc-launch" role="dialog" aria-modal="true" aria-labelledby="tc-launch-title" hidden>
        <div class="tc-modal-card">
            <h2 id="tc-launch-title"></h2>
            <p id="tc-launch-detail" class="tc-muted"></p>
            <div class="tc-modal-acts">
                <button type="button" class="tc-ctl" id="tc-launch-cancel"></button>
                <button type="button" class="tc-ctl tc-ctl--ok" id="tc-launch-go"></button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="tc-config">@json($config)</script>
<script src="{{ $assets['livekit-client.umd.js'] }}"></script>
<script src="{{ $assets['teamcalls-core.js'] }}"></script>
@isset($assets['teamcalls-realtime.js'])<script src="{{ $assets['teamcalls-realtime.js'] }}"></script>@endisset
<script src="{{ $assets['teamcalls-ui.js'] }}"></script>
