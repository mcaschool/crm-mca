{{-- Aviso in-app de lead nuevo: campana (toggle de sonido) + pop-up v4 + sonido.
     El root usa display:contents para no alterar el flex de la topbar. --}}
<div
    wire:poll.10s="check"
    style="display:contents"
    x-data="{
        sound: @js($soundEnabled),
        audio: null,
        toasts: [],
        seq: 0,
        init() {
            this.audio = new Audio(@js(asset('sounds/new-lead.wav')));
            this.audio.preload = 'auto';
        },
        toggle() {
            // El clic es el gesto de usuario que desbloquea el autoplay del navegador.
            $wire.toggleSound();
            this.sound = !this.sound;
            if (this.sound) { this.play(); }
        },
        play() {
            if (!this.audio) return;
            try { this.audio.currentTime = 0; this.audio.play().catch(() => {}); } catch (e) {}
        },
        notify(raw) {
            const d = Array.isArray(raw) ? (raw[0] || {}) : (raw || {});
            const count = d.count || 1;
            const id = ++this.seq;
            this.toasts.push({
                id,
                heading: count > 1 ? @js(__('Leads nuevos')) : @js(__('Lead nuevo')),
                title: d.title || @js(__('Nuevo lead')),
                url: d.url || @js(route('crm.leads.index')),
            });
            if (this.sound) { this.play(); }
            const self = this;
            setTimeout(() => { self.toasts = self.toasts.filter(t => t.id !== id); }, 9000);
        },
        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); }
    }"
    x-on:crm-new-leads.window="notify($event.detail)"
>
    <button
        type="button"
        class="icon-btn ui-sound"
        :class="{ 'is-on': sound }"
        @click="toggle()"
        :title="sound ? @js(__('Sonido de avisos activado — clic para silenciar')) : @js(__('Activar sonido de avisos de leads'))"
        :aria-label="sound ? @js(__('Silenciar avisos')) : @js(__('Activar sonido de avisos'))"
    >
        <span class="dot" x-show="!sound" x-cloak></span>
        <x-ui.icon name="bell" />
    </button>

    {{-- Los pop-up viven en <body> (x-teleport) para ser inmunes al morph del poll. Estilos: x-ui.kit (.ui-toasts). --}}
    <template x-teleport="body">
        <div class="ui-toasts" aria-live="polite" aria-atomic="false">
            <template x-for="t in toasts" :key="t.id">
                <div class="ui-toast">
                    <span class="ui-toast-ic ui-tone--blue"><x-ui.icon name="user-plus" /></span>
                    <div class="ui-toast-b">
                        <div class="ui-toast-kicker" x-text="t.heading"></div>
                        <div class="ui-toast-title" x-text="t.title"></div>
                        <a class="ui-link" :href="t.url">{{ __('Ver lead') }} <x-ui.icon name="chevron-right" /></a>
                    </div>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" @click="dismiss(t.id)" aria-label="{{ __('Cerrar') }}">
                        <x-ui.icon name="x" />
                    </button>
                </div>
            </template>
        </div>
    </template>
</div>
