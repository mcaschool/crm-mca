<div class="mca-page">
    <x-ui.page-header :title="__('Configurar').': '.$meta['label']" :subtitle="__('Las credenciales se guardan cifradas. Una vez guardadas no se vuelven a mostrar: se enmascaran y solo pueden reemplazarse.')" :back="route('integrations.index')" :back-label="__('Volver a integraciones')" />

    <form class="ui-card ui-narrow" wire:submit="save" novalidate>
        <div class="ui-card-h"><x-ui.icon name="plug" class="ui-card-ic" /><h2>{{ $meta['label'] }}</h2>
            <span class="sp"></span>
            <span class="ui-card-sub"><x-ui.icon name="lock" class="ic" /> {{ __('Credenciales cifradas') }}</span>
        </div>
        <div class="ui-card-p">
            <div class="ui-form-grid">
                <x-ui.field @class(['ui-span-full' => $meta['providers'] === []]) :label="__('Nombre')" for="cfg-name" required :error="$errors->first('name')">
                    <input id="cfg-name" type="text" wire:model="name" maxlength="120" @class(['ui-input', 'is-invalid' => $errors->has('name')]) @error('name') aria-describedby="cfg-name-error" @enderror>
                </x-ui.field>

                @if ($meta['providers'] !== [])
                    <x-ui.field :label="__('Proveedor')" for="cfg-provider" required :error="$errors->first('provider')">
                        <select id="cfg-provider" wire:model="provider" @class(['ui-select', 'is-invalid' => $errors->has('provider')])>
                            @foreach ($meta['providers'] as $p)
                                <option value="{{ $p }}">{{ $p }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @foreach ($meta['fields'] as $field)
                    @php
                        $fid = 'cfg-'.$field['key'];
                        $masked = $field['secret'] ? ($maskedPreview[$field['key']] ?? null) : null;
                        $hasError = $errors->has('inputs.'.$field['key']);
                    @endphp
                    <x-ui.field wire:key="cfg-field-{{ $field['key'] }}" @class(['ui-span-full' => str_contains($field['key'], 'url')]) :label="__($field['label'])" :for="$fid" :required="$field['required'] && ! $masked" :error="$errors->first('inputs.'.$field['key'])">
                        @if ($field['type'] === 'select')
                            <select id="{{ $fid }}" wire:model="inputs.{{ $field['key'] }}" @class(['ui-select', 'is-invalid' => $hasError])>
                                @foreach (($field['options'] ?? []) as $opt)
                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                @endforeach
                            </select>
                        @else
                            {{-- Sin value=: los secretos empiezan vacíos; se escriben para reemplazar. --}}
                            <input id="{{ $fid }}" type="{{ $field['type'] === 'number' ? 'number' : ($field['secret'] ? 'password' : 'text') }}"
                                   wire:model="inputs.{{ $field['key'] }}" autocomplete="off" spellcheck="false"
                                   placeholder="{{ $field['secret'] ? __('Reemplazar credencial') : '' }}"
                                   @class(['ui-input', 'is-invalid' => $hasError])
                                   @if ($hasError) aria-describedby="{{ $fid }}-error" @elseif ($masked) aria-describedby="{{ $fid }}-now" @endif>
                        @endif

                        @if ($masked)
                            {{-- Barrera: el secreto actual solo se muestra ENMASCARADO, nunca en el input. --}}
                            <p class="ui-help" id="{{ $fid }}-now"><x-ui.icon name="lock" class="ic" /> {{ __('Actual') }}: <span class="ui-mono">{{ $masked }}</span> — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}</p>
                        @endif
                    </x-ui.field>
                @endforeach
            </div>
        </div>
        <div class="ui-card-f ui-card-f--end">
            <x-ui.button :href="route('integrations.index')">{{ __('Cancelar') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar') }}</x-ui.button>
        </div>
    </form>
</div>
