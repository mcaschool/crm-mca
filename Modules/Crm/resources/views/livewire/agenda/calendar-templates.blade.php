<div class="mca-page">
    <x-ui.page-header :title="__('Plantillas de Calendario')" :subtitle="__('Correos que se envían a los invitados y a quien reserva: invitación, reprogramación, cancelación y recordatorios.')" />

    @if ($notice !== '')
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ $notice }}</div>
    @endif

    <div class="ui-card">
        <div class="ui-toolbar">
            <x-ui.select wire:model.live="key" :aria-label="__('Correo')" class="ui-tpl-key">
                @foreach ($keys as $k)<option value="{{ $k }}">{{ \Modules\Crm\Services\Booking\CalendarTemplates::label($k) }}</option>@endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="locale" :aria-label="__('Idioma')">
                <option value="es">Español</option>
                <option value="en">English</option>
            </x-ui.select>
            <span class="sp"></span>
            <x-ui.badge :tone="$custom ? 'blue' : 'neutral'" dot>{{ $custom ? __('Personalizada') : __('Predeterminada') }}</x-ui.badge>
        </div>

        <div class="ui-tpl-grid">
            <form class="ui-tpl-edit" wire:submit="save" novalidate>
                <x-ui.field :label="__('Asunto')" for="tpl-subject" :error="$errors->first('subject')">
                    <input id="tpl-subject" type="text" wire:model.live.debounce.500ms="subject" maxlength="200" @class(['ui-input', 'is-invalid' => $errors->has('subject')])>
                </x-ui.field>
                <x-ui.field :label="__('Cuerpo')" for="tpl-body" :hint="__('texto; los enlaces https se convierten en enlaces')" :error="$errors->first('body') ?: ($unknown !== [] ? __('Variables no permitidas: :list', ['list' => implode(', ', array_map(fn ($v) => str_repeat('{', 2).$v.str_repeat('}', 2), $unknown))]) : null)">
                    <textarea id="tpl-body" wire:model.live.debounce.500ms="body" maxlength="5000" spellcheck="true" @class(['ui-input ui-textarea ui-tpl-body', 'is-invalid' => $errors->has('body') || $unknown !== []])></textarea>
                </x-ui.field>
                <div class="ui-form-actions ui-form-actions--end">
                    @if ($custom)
                        <x-ui.button variant="ghost" icon="refresh" wire:click="restore" wire:confirm="{{ __('¿Restaurar el texto predeterminado? Se perderá la personalización de este correo e idioma.') }}">{{ __('Restaurar predeterminada') }}</x-ui.button>
                    @endif
                    <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar plantilla') }}</x-ui.button>
                </div>
            </form>
            <aside class="ui-tpl-side" aria-label="{{ __('Vista previa') }}">
                <p class="ui-card-kicker ui-card-kicker--flush">{{ __('Variables disponibles') }}</p>
                <div class="ui-tpl-vars">
                    @foreach ($variables as $v)<code>{{ str_repeat('{', 2).$v.str_repeat('}', 2) }}</code>@endforeach
                </div>
                <p class="ui-card-kicker ui-card-kicker--flush">{{ __('Vista previa') }}</p>
                <div class="ui-tpl-preview">
                    <div class="ui-tpl-preview-subject">{{ $previewSubject }}</div>
                    {!! $previewHtml !!}
                </div>
                <p class="ui-help">{{ __('Vista previa con datos de ejemplo. El correo real añade el archivo .ics y los botones para agregar la cita al calendario.') }}</p>
            </aside>
        </div>
    </div>
</div>
