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
        class="icon-btn"
        :class="{ 'snd-on': sound }"
        @click="toggle()"
        :title="sound ? @js(__('Sonido de avisos activado — clic para silenciar')) : @js(__('Activar sonido de avisos de leads'))"
        :aria-label="sound ? @js(__('Silenciar avisos')) : @js(__('Activar sonido de avisos'))"
    >
        <span class="dot" x-show="!sound" x-cloak></span>
        <x-ui.icon name="bell" style="width:19px;height:19px" />
    </button>

    {{-- Los pop-up viven en <body> (x-teleport) para ser inmunes al morph del poll. --}}
    <template x-teleport="body">
        <div class="mca-lead-toasts" aria-live="polite" aria-atomic="false">
            <template x-for="t in toasts" :key="t.id">
                <div class="mca-lead-toast">
                    <span class="lt-ic"><x-ui.icon name="bell" style="width:16px;height:16px" /></span>
                    <div class="lt-bd">
                        <div class="lt-h" x-text="t.heading"></div>
                        <div class="lt-t" x-text="t.title"></div>
                        <a class="lt-go" :href="t.url">{{ __('Ver lead') }} <x-ui.icon name="chevron-right" style="width:13px;height:13px" /></a>
                    </div>
                    <button type="button" class="lt-x" @click="dismiss(t.id)" aria-label="{{ __('Cerrar') }}">
                        <x-ui.icon name="x" style="width:14px;height:14px" />
                    </button>
                </div>
            </template>
        </div>
    </template>

    @once
        <style>
            .mca-topbar .icon-btn.snd-on{color:var(--mca-gold);border-color:var(--mca-gold);background:var(--mca-gold-soft)}
            .mca-lead-toasts{position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:min(360px,calc(100vw - 32px));pointer-events:none}
            .mca-lead-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:11px;background:var(--mca-card);border:1px solid var(--mca-card-border);border-left:3px solid var(--mca-gold);border-radius:var(--mca-radius-sm);box-shadow:var(--mca-shadow);padding:12px 12px 12px 13px;animation:mcaLeadIn .28s cubic-bezier(.2,.7,.3,1)}
            .mca-lead-toast .lt-ic{flex:0 0 auto;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:var(--mca-gold-soft);color:var(--mca-gold)}
            .mca-lead-toast .lt-bd{flex:1 1 auto;min-width:0}
            .mca-lead-toast .lt-h{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mca-ink-3)}
            .mca-lead-toast .lt-t{font-size:14px;font-weight:600;color:var(--mca-ink);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .mca-lead-toast .lt-go{display:inline-flex;align-items:center;gap:3px;margin-top:6px;font-size:12.5px;font-weight:600;color:var(--mca-blue);text-decoration:none}
            .mca-lead-toast .lt-go:hover{text-decoration:underline}
            .mca-lead-toast .lt-x{flex:0 0 auto;border:0;background:transparent;color:var(--mca-ink-3);cursor:pointer;padding:2px;border-radius:6px;line-height:0}
            .mca-lead-toast .lt-x:hover{background:var(--mca-page-bg);color:var(--mca-ink-2)}
            @keyframes mcaLeadIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
            @media (prefers-reduced-motion:reduce){.mca-lead-toast{animation:none}}
        </style>
    @endonce
</div>
