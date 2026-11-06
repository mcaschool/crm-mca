{{--
  Campo de texto largo del asesor (instrucciones, límites, reglas de transferencia): textarea
  redimensionable con contador discreto y «Ampliar editor» (modal de TEXTO PLANO, no enriquecido).
  El textarea inline conserva su `wire:model` (Livewire es el dueño del dato). El modal es Alpine
  puro: edita una copia y, al «Aplicar», la escribe en el textarea y avisa a Livewire con un evento
  `input`; «Cancelar»/cerrar con cambios sin aplicar pide confirmación para no perder texto.

  Parámetros: $model (propiedad wire), $id, $label, $error, $max, $rows, $size ('xl'|'lg'|null),
  $placeholder, $help (opcional), $span (bool, ocupa todo el ancho del grid).
--}}
@props([
    'model',
    'id',
    'label',
    'error' => null,
    'max' => 20000,
    'rows' => 4,
    'size' => null,
    'placeholder' => '',
    'help' => null,
    'span' => false,
])
<x-ui.field :label="$label" :for="$id" :error="$error" :help="$help" :class="$span ? 'ui-span-full' : ''">
    <div class="ui-promptfield"
         data-discard="{{ __('Hay cambios sin aplicar en el editor ampliado. ¿Descartarlos?') }}"
         x-data="{
            max: {{ (int) $max }},
            len: 0,
            open: false,
            draft: '',
            baseline: '',
            count() { this.len = [...(this.$refs.field.value || '')].length },   // puntos de código (como mb_strlen)
            openModal() {
                this.draft = this.$refs.field.value;
                this.baseline = this.draft;
                this.open = true;
                document.body.style.overflow = 'hidden';
                this.$nextTick(() => { if (this.$refs.modalField) this.$refs.modalField.focus(); });
            },
            closeModal() { this.open = false; document.body.style.overflow = ''; },
            apply() {
                const f = this.$refs.field;
                f.value = this.draft;
                f.dispatchEvent(new Event('input', { bubbles: true }));
                this.count();
                this.closeModal();
            },
            requestClose() {
                if (this.draft !== this.baseline && ! window.confirm(this.$root.dataset.discard)) return;
                this.closeModal();
            },
            copyAll() {
                if (navigator.clipboard) { navigator.clipboard.writeText(this.draft); }
                else { this.$refs.modalField.select(); document.execCommand('copy'); }
            },
         }"
         x-init="count()">
        {{-- Sin `maxlength` (cuenta unidades UTF-16 y divergería con emojis del contador y de mb_strlen):
             el límite lo marcan el contador (puntos de código) y la validación de Laravel; MEDIUMTEXT no trunca. --}}
        <textarea id="{{ $id }}" wire:model="{{ $model }}" x-ref="field" @input="count()"
                  rows="{{ (int) $rows }}" spellcheck="true"
                  placeholder="{{ $placeholder }}"
                  @class(['ui-input ui-textarea', 'ui-textarea--xl' => $size === 'xl', 'ui-textarea--lg' => $size === 'lg', 'is-invalid' => (bool) $error])></textarea>

        <div class="ui-prompt-tools">
            <button type="button" class="ui-link" @click="openModal()"><x-ui.icon name="external-link" /> {{ __('Ampliar editor') }}</button>
            <span class="ui-prompt-count" :class="{ 'is-max': len >= max }" aria-live="polite">
                <span x-text="len.toLocaleString()">0</span> / {{ number_format((int) $max) }}
            </span>
        </div>

        {{-- Editor ampliado (posición fija: cubre la ventana). La visibilidad se controla con una clase
             (:class), no con x-show, por un problema de registro de x-show en este montaje Livewire+Alpine. --}}
        <div :class="{ 'is-open': open }" class="ui-editor-ovl" @click.self="requestClose()" @keydown.escape.window="open && requestClose()">
            <div class="ui-editor-modal" role="dialog" aria-modal="true" aria-label="{{ $label }}">
                    <div class="ui-editor-h">
                        <x-ui.icon name="user-cog" />
                        <h3>{{ $label }}</h3>
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon" @click="requestClose()" aria-label="{{ __('Cerrar') }}"><x-ui.icon name="x" /></button>
                    </div>
                    <div class="ui-editor-body">
                        <textarea x-ref="modalField" x-model="draft" spellcheck="true"
                                  class="ui-editor-area" placeholder="{{ $placeholder }}" aria-label="{{ $label }}"></textarea>
                    </div>
                    <div class="ui-editor-f">
                        <span class="ui-prompt-count" :class="{ 'is-max': (draft ? [...draft].length : 0) >= max }">
                            <span x-text="(draft ? [...draft].length : 0).toLocaleString()">0</span> / {{ number_format((int) $max) }}
                        </span>
                        <span class="sp"></span>
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="copyAll()"><x-ui.icon name="file-text" /> {{ __('Copiar todo') }}</button>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="requestClose()">{{ __('Cancelar') }}</button>
                        <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="apply()">{{ __('Aplicar') }}</button>
                    </div>
                </div>
            </div>
        </div>
</x-ui.field>
