{{--
    Desplegable propio del Centro de Conocimiento (Alpine, incluido con Livewire).

    El enlace con Livewire NO cambia: un <select> nativo oculto lleva el mismo wire:model
    (con o sin .live) y este componente solo le escribe el valor y dispara «change». El valor
    mostrado se lee de $wire (reactivo), así refleja tanto la elección local como cualquier
    cambio del servidor (p. ej. «Limpiar»).

    Parámetros:
      $model        propiedad Livewire (string)
      $live         bool — wire:model.live (filtros) o diferido (formularios de subida)
      $options      array valor => etiqueta (ya traducida)
      $placeholder  texto cuando no hay valor
      $label        prefijo gris del botón (filtros) o null
      $aria         etiqueta accesible
      $emptyOption  bool — ofrecer el valor vacío como opción (p. ej. «Todas»)
      $field        bool — ancho completo (tarjetas) en vez de «pastilla» (filtros)
--}}
@php
    $kcDdLive = $live ?? false;
    $kcDdLabel = $label ?? null;
    $kcDdEmpty = $emptyOption ?? false;
    $kcDdField = $field ?? false;
    $kcDdOptions = collect($options)->mapWithKeys(fn ($l, $v) => [(string) $v => (string) $l]);
@endphp
<div @class(['kc-dd', 'kc-dd-field' => $kcDdField]) wire:key="dd-{{ $model }}"
     x-data="{
        open: false,
        active: -1,
        prop: @js($model),
        get value() { const v = this.$wire[this.prop]; return v === null || v === undefined ? '' : String(v) },
        items() { return [...this.$refs.panel.querySelectorAll('[role=option]')] },
        {{-- El texto vacío sale del <option> nativo (Livewire lo mantiene al día, p. ej. «(20)»). --}}
        labelFor(v) { const o = this.items().find(e => e.dataset.value === v); return o ? o.dataset.label : (this.$refs.native.options[0]?.text ?? '') },
        toggle() { this.open ? this.close(false) : this.show() },
        show() {
            this.open = true;
            this.$nextTick(() => {
                const list = this.items();
                this.active = Math.max(0, list.findIndex(e => e.dataset.value === this.value));
                this.focusActive();
            });
        },
        close(refocus) { this.open = false; if (refocus) { this.$refs.btn.focus() } },
        move(delta) {
            const list = this.items();
            if (list.length === 0) { return }
            this.active = (this.active + delta + list.length) % list.length;
            this.focusActive();
        },
        edge(last) { const list = this.items(); this.active = last ? list.length - 1 : 0; this.focusActive() },
        focusActive() { const el = this.items()[this.active]; if (el) { el.focus(); el.scrollIntoView({ block: 'nearest' }) } },
        choose(v) {
            const s = this.$refs.native;
            s.value = v;
            s.dispatchEvent(new Event('change', { bubbles: true }));
            this.close(true);
        },
     }"
     {{-- Escape a nivel de ventana: cierra el panel abierto aunque el foco se haya movido
          (p. ej. una respuesta de Livewire que llega con el panel ya abierto). --}}
     @keydown.escape.window="if (open) { $event.preventDefault(); close(true) }"
     @click.outside="close(false)">
    <select wire:model{{ $kcDdLive ? '.live' : '' }}="{{ $model }}" x-ref="native" class="kc-native" tabindex="-1" aria-hidden="true">
        <option value="">{{ $placeholder }}</option>
        @foreach ($kcDdOptions as $v => $l)
            <option value="{{ $v }}">{{ $l }}</option>
        @endforeach
    </select>

    <button type="button" x-ref="btn" class="kc-dd-btn" @click="toggle()"
            @keydown.arrow-down.prevent="show()" @keydown.arrow-up.prevent="show()"
            aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" aria-label="{{ $aria }}">
        @if ($kcDdLabel)
            <span class="kc-dd-lbl">{{ $kcDdLabel }}</span>
        @endif
        <span class="kc-dd-val" wire:ignore x-text="labelFor(value)" :class="{ 'is-ph': value === '' && ! @js($kcDdEmpty) }">{{ $placeholder }}</span>
        <x-ui.icon name="chevron-down" class="kc-dd-chev" />
    </button>

    <div x-ref="panel" class="kc-dd-panel" x-show="open" x-cloak role="listbox" aria-label="{{ $aria }}"
         @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
         @keydown.home.prevent="edge(false)" @keydown.end.prevent="edge(true)"
         @keydown.enter.prevent="$event.target.closest('[role=option]')?.click()"
         @keydown.tab="close(false)">
        @if ($kcDdEmpty)
            <button type="button" role="option" class="kc-dd-opt" data-value="" data-label="{{ $placeholder }}"
                    :class="{ 'on': value === '' }" :aria-selected="value === '' ? 'true' : 'false'" @click="choose('')">
                <span>{{ $placeholder }}</span>
                <svg class="kc-dd-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
        @endif
        @forelse ($kcDdOptions as $v => $l)
            <button type="button" role="option" class="kc-dd-opt" data-value="{{ $v }}" data-label="{{ $l }}" wire:key="ddo-{{ $model }}-{{ $v }}"
                    :class="{ 'on': value === @js($v) }" :aria-selected="value === @js($v) ? 'true' : 'false'" @click="choose(@js($v))">
                <span>{{ $l }}</span>
                <svg class="kc-dd-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
        @empty
            <div class="kc-dd-none">{{ __('Sin resultados') }}</div>
        @endforelse
    </div>
</div>
