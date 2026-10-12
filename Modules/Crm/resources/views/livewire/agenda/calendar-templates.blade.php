<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    <div style="max-width:1100px;display:flex;flex-direction:column;gap:16px">
        @if ($notice !== '')
            <div class="toast ok" role="status" style="margin:0"><x-ui.icon name="check" class="i15" /> {{ $notice }}</div>
        @endif

        <div class="panel">
            <div class="ag-bar">
                <h2 style="text-transform:none"><x-ui.icon name="mail" class="i18" /> {{ __('Plantillas de Calendario') }}</h2>
                <span class="count">{{ $custom ? __('Personalizada') : __('Predeterminada') }}</span>
                <div style="flex:1"></div>
                <select class="fbtn" wire:model.live="key" aria-label="{{ __('Correo') }}">
                    @foreach ($keys as $k)<option value="{{ $k }}">{{ \Modules\Crm\Services\Booking\CalendarTemplates::label($k) }}</option>@endforeach
                </select>
                <select class="fbtn" wire:model.live="locale" aria-label="{{ __('Idioma') }}">
                    <option value="es">Español</option>
                    <option value="en">English</option>
                </select>
            </div>

            <div class="ag-detail">
                <form class="ag-main" wire:submit="save">
                    <div class="ag-f">
                        <label for="tpl-subject">{{ __('Asunto') }}</label>
                        <input id="tpl-subject" type="text" wire:model.live.debounce.500ms="subject" maxlength="200">
                        @error('subject') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="ag-f" style="margin-top:12px">
                        <label for="tpl-body">{{ __('Cuerpo (texto; los enlaces https se convierten en enlaces)') }}</label>
                        <textarea id="tpl-body" wire:model.live.debounce.500ms="body" maxlength="5000" style="min-height:320px;font-family:ui-monospace,Consolas,monospace;font-size:13px"></textarea>
                        @error('body') <div class="err">{{ $message }}</div> @enderror
                        @if ($unknown !== [])
                            <div class="err">{{ __('Variables no permitidas: :list', ['list' => implode(', ', array_map(fn ($v) => str_repeat('{', 2).$v.str_repeat('}', 2), $unknown))]) }}</div>
                        @endif
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
                        @if ($custom)
                            <button type="button" class="ghost" wire:click="restore" wire:confirm="{{ __('¿Restaurar el texto predeterminado? Se perderá la personalización de este correo e idioma.') }}">{{ __('Restaurar predeterminada') }}</button>
                        @endif
                        <button type="submit" class="ghost solid" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Guardar plantilla') }}</button>
                    </div>
                </form>
                <aside class="ag-side">
                    <h3>{{ __('Variables disponibles') }}</h3>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        @foreach ($variables as $v)<code style="font-size:11.5px;background:#F1F6FC;border-radius:6px;padding:2px 6px">{{ str_repeat('{', 2).$v.str_repeat('}', 2) }}</code>@endforeach
                    </div>
                    <h3 style="margin-top:18px">{{ __('Vista previa') }}</h3>
                    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;background:#fff">
                        <div style="font-weight:700;font-size:13.5px;margin-bottom:8px">{{ $previewSubject }}</div>
                        {!! $previewHtml !!}
                    </div>
                    <p style="font-size:11.5px;color:var(--muted);margin-top:8px">{{ __('Vista previa con datos de ejemplo. El correo real añade el archivo .ics y los botones para agregar la cita al calendario.') }}</p>
                </aside>
            </div>
        </div>
    </div>
</div>
