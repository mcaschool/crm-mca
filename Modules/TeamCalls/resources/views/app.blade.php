{{--
  TeamCalls · interfaz web de llamadas (fase 3C, SOLO desarrollo). Sin secretos ni salas en la página:
  la configuración solo lleva rutas de la API (con el id de la llamada como plantilla) y textos. Toda la
  lógica de medios es del SDK oficial de LiveKit; la de llamadas, de la API existente.
--}}
<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ $assets['teamcalls-ui.css'] }}">
    @endpush

    <div class="mca-page tc-page">
        <x-ui.page-header :title="__('teamcalls.ui.title')" :subtitle="__('teamcalls.ui.subtitle')">
            <x-slot name="badge"><x-ui.badge tone="gold">{{ __('teamcalls.ui.dev_badge') }}</x-ui.badge></x-slot>
        </x-ui.page-header>

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
                </div>
                <div class="tc-empty" id="tc-empty">
                    <x-ui.icon name="phone" class="tc-empty-ic" />
                    <p>{{ __('teamcalls.ui.stage_empty') }}</p>
                </div>
                <div class="tc-tiles" id="tc-tiles"></div>
                <div class="tc-controls" id="tc-controls" hidden>
                    <button type="button" class="tc-ctl" id="tc-mic" aria-pressed="true"></button>
                    <button type="button" class="tc-ctl" id="tc-cam" aria-pressed="true"></button>
                    <button type="button" class="tc-ctl tc-ctl--danger" id="tc-hangup"></button>
                </div>
                <div class="tc-bottom" id="tc-bottom" hidden>
                    <details class="tc-details">
                        <summary>{{ __('teamcalls.ui.participants') }}</summary>
                        <ul class="tc-list" id="tc-participants"></ul>
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
                <div class="tc-ring" aria-hidden="true"><x-ui.icon name="phone" /></div>
                <h2 id="tc-incoming-title"></h2>
                <p id="tc-incoming-kind" class="tc-muted"></p>
                <div class="tc-modal-acts">
                    <button type="button" class="tc-ctl tc-ctl--danger" id="tc-decline"></button>
                    <button type="button" class="tc-ctl tc-ctl--ok" id="tc-accept"></button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="tc-config">@json($config)</script>
    <script src="{{ $assets['livekit-client.umd.js'] }}"></script>
    <script src="{{ $assets['teamcalls-ui.js'] }}"></script>
</x-app-layout>
