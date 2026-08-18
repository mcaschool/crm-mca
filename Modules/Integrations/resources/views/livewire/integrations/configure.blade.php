<div>
    <x-ui.styles />
    <div class="mca-panel max-w-2xl mx-auto sm:px-6 lg:px-8 px-4" style="padding-top:22px;padding-bottom:34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('integrations.index') }}" class="btn btn-ghost btn-sm" title="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ __('Configurar') }}: {{ $meta['label'] }}</h1>
                    <p class="mca-sub">{{ __('Las credenciales se guardan cifradas. Una vez guardadas no se vuelven a mostrar: se enmascaran y solo pueden reemplazarse.') }}</p>
                </div>
            </div>
        </div>

        <div class="card card-p fade">
            <form wire:submit="save">
                <div class="field">
                    <label>{{ __('Nombre') }}</label>
                    <input type="text" wire:model="name">
                    @error('name') <span class="mca-err">{{ $message }}</span> @enderror
                </div>

                @if ($meta['providers'] !== [])
                    <div class="field">
                        <label>{{ __('Proveedor') }}</label>
                        <select wire:model="provider">
                            @foreach ($meta['providers'] as $p)
                                <option value="{{ $p }}">{{ $p }}</option>
                            @endforeach
                        </select>
                        @error('provider') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                @endif

                @foreach ($meta['fields'] as $field)
                    <div class="field">
                        <label>{{ __($field['label']) }}</label>

                        @if ($field['secret'] && ($maskedPreview[$field['key']] ?? null))
                            {{-- Barrera: el secreto actual solo se muestra ENMASCARADO, nunca en el input. --}}
                            <p class="mca-help" style="margin-top:0;margin-bottom:6px">
                                {{ __('Actual') }}: <span style="font-family:ui-monospace,monospace">{{ $maskedPreview[$field['key']] }}</span>
                                — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}
                            </p>
                        @endif

                        @if ($field['type'] === 'select')
                            <select wire:model="inputs.{{ $field['key'] }}">
                                @foreach (($field['options'] ?? []) as $opt)
                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                @endforeach
                            </select>
                        @else
                            {{-- Sin value=: los secretos empiezan vacíos; se escriben para reemplazar. --}}
                            <input type="{{ $field['type'] === 'number' ? 'number' : ($field['secret'] ? 'password' : 'text') }}"
                                   wire:model="inputs.{{ $field['key'] }}" autocomplete="off"
                                   placeholder="{{ $field['secret'] ? __('Reemplazar credencial') : '' }}"
                                   @if ($field['type'] === 'number') style="width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:14px;font-family:inherit;color:var(--ink);background:#fff" @endif>
                        @endif

                        @error('inputs.'.$field['key']) <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                @endforeach

                <div style="display:flex;align-items:center;gap:12px;margin-top:4px">
                    <button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button>
                    <a href="{{ route('integrations.index') }}" class="btn btn-ghost">{{ __('Cancelar') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
