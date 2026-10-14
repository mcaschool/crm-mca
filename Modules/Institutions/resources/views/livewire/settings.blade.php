@php
    $sizeMin = \Modules\Institutions\Models\Institution::LOGO_SIZE_MIN;
    $sizeMax = \Modules\Institutions\Models\Institution::LOGO_SIZE_MAX;
@endphp
<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.settings-tabs />

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <div class="ui-stack">
            {{-- Logo institucional --}}
            <section class="ui-card" aria-labelledby="logo-title">
                <div class="ui-card-h"><x-ui.icon name="image" class="ui-card-ic" /><h2 id="logo-title">{{ __('Logo institucional') }}</h2></div>
                <div class="ui-card-p ui-stack">
                    <p class="ui-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB. Reemplaza el icono + «:name» del encabezado del panel.', ['name' => $institution->name]) }}</p>

                    <div class="ui-media">
                        <div class="ui-thumb">
                            @if ($logo && $logo->isPreviewable())
                                <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Vista previa') }}">
                            @elseif ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $institution->name }}">
                            @else
                                <span class="ui-thumb-empty"><x-ui.icon name="shield" /> {{ __('Sin logo') }}</span>
                            @endif
                        </div>

                        <div class="ui-media-body">
                            <div class="ui-inline">
                                <label class="ui-btn ui-btn--secondary ui-btn--sm">
                                    <x-ui.icon name="upload" /> {{ __('Elegir imagen') }}
                                    <input type="file" wire:model="logo" accept=".png,.jpg,.jpeg,.svg,.webp" class="ui-sr">
                                </label>
                                @if ($logo)
                                    <button type="button" wire:click="saveLogo" wire:loading.attr="disabled" wire:target="saveLogo" class="ui-btn ui-btn--primary ui-btn--sm">
                                        <span wire:loading.remove wire:target="saveLogo" class="ui-btn-label"><x-ui.icon name="check" /> {{ __('Guardar logo') }}</span>
                                        <span wire:loading wire:target="saveLogo"><span class="ui-btn-label"><span class="ui-spin"></span> {{ __('Guardando…') }}</span></span>
                                    </button>
                                @endif
                                @if ($logoUrl)
                                    <button type="button" wire:click="removeLogo" wire:confirm="{{ __('¿Quitar el logo y volver a la marca por defecto?') }}" class="ui-btn ui-btn--ghost ui-btn--sm"><x-ui.icon name="trash" /> {{ __('Quitar') }}</button>
                                @endif
                                <span wire:loading wire:target="logo" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                            </div>
                            @error('logo') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            <p class="ui-help">{{ __('Recomendado: imagen horizontal con fondo transparente (PNG/SVG) para que se vea bien sobre el encabezado claro.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Tamaño del logo (alto en px). La previsualización refleja el valor elegido. --}}
            @if ($logoUrl)
                <section class="ui-card" aria-labelledby="logo-size-title">
                    <div class="ui-card-h"><x-ui.icon name="layers" class="ui-card-ic" /><h2 id="logo-size-title">{{ __('Tamaño del logo') }}</h2></div>
                    <div class="ui-card-p ui-stack">
                        <p class="ui-help">{{ __('Alto del logo en el encabezado, entre :min y :max px.', ['min' => $sizeMin, 'max' => $sizeMax]) }}</p>

                        <div class="ui-media">
                            {{-- Previsualización a tamaño real sobre el fondo del menú lateral --}}
                            <div class="ui-thumb ui-thumb--nav">
                                <img src="{{ $logoUrl }}" alt="{{ $institution->name }}" style="max-height:{{ $logoSize }}px">
                            </div>

                            <div class="ui-media-body">
                                <div class="ui-inline">
                                    <input id="logo-size" type="number" wire:model.live="logoSize" min="{{ $sizeMin }}" max="{{ $sizeMax }}" step="1" inputmode="numeric"
                                        aria-label="{{ __('Alto del logo en píxeles') }}" @if ($errors->has('logoSize')) aria-describedby="logo-size-error" @endif
                                        @class(['ui-input', 'ui-input--num', 'is-invalid' => $errors->has('logoSize')])>
                                    <span class="ui-inline-unit">px</span>
                                    <div class="ui-seg" role="group" aria-label="{{ __('Tamaños sugeridos') }}">
                                        <button type="button" wire:click="$set('logoSize', 32)" aria-pressed="{{ (int) $logoSize === 32 ? 'true' : 'false' }}">{{ __('Pequeño') }}</button>
                                        <button type="button" wire:click="$set('logoSize', 44)" aria-pressed="{{ (int) $logoSize === 44 ? 'true' : 'false' }}">{{ __('Mediano') }}</button>
                                        <button type="button" wire:click="$set('logoSize', 64)" aria-pressed="{{ (int) $logoSize === 64 ? 'true' : 'false' }}">{{ __('Grande') }}</button>
                                    </div>
                                </div>
                                @error('logoSize') <p class="ui-error" id="logo-size-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                                <p class="ui-help">{{ __('Valor actual: :size px. El sidebar mostrará el logo a este alto.', ['size' => $logoSize]) }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="ui-card-f ui-card-f--end">
                        <button type="button" wire:click="saveSize" class="ui-btn ui-btn--primary"><x-ui.icon name="check" /> {{ __('Guardar tamaño') }}</button>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
