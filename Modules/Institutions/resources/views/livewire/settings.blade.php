<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">Ajustes</h1>
                <p class="mca-sub">Marca del panel. El logo aparece arriba a la izquierda; si no subes uno, se usa la marca por defecto.</p>
            </div>
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- Logo institucional --}}
        <div class="card card-p fade">
            <h3 class="mca-h1" style="font-size:16px;margin-bottom:4px">Logo institucional</h3>
            <p class="mca-sub" style="margin-bottom:16px">PNG, JPG, SVG o WebP · máx 1 MB. Reemplaza el icono + «{{ $institution->name }}» del encabezado del panel.</p>

            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                <div style="width:132px;height:72px;border:1px solid var(--line);border-radius:12px;background:#fff;display:grid;place-items:center;overflow:hidden;padding:8px">
                    @if ($logo && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" alt="preview" style="max-width:100%;max-height:100%;object-fit:contain">
                    @elseif ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $institution->name }}" style="max-width:100%;max-height:100%;object-fit:contain">
                    @else
                        <span style="display:flex;align-items:center;gap:8px;color:var(--muted)"><x-ui.icon name="shield" style="width:22px;height:22px" /> <span style="font-size:12.5px">Sin logo</span></span>
                    @endif
                </div>

                <div style="flex:1;min-width:220px">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <label class="mca-filebtn">
                            <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> Elegir imagen
                            <input type="file" wire:model="logo" accept=".png,.jpg,.jpeg,.svg,.webp" class="hidden">
                        </label>
                        @if ($logo)
                            <button type="button" wire:click="saveLogo" wire:loading.attr="disabled" wire:target="saveLogo" class="btn btn-primary btn-sm">
                                <span wire:loading.remove wire:target="saveLogo"><x-ui.icon name="check" class="ic" style="width:15px;height:15px" /> Guardar logo</span>
                                <span wire:loading wire:target="saveLogo"><span class="mca-spin"></span> Guardando…</span>
                            </button>
                        @endif
                        @if ($logoUrl)
                            <button type="button" wire:click="removeLogo" wire:confirm="¿Quitar el logo y volver a la marca por defecto?" class="btn btn-ghost btn-sm">Quitar</button>
                        @endif
                        <span wire:loading wire:target="logo" class="mca-help"><span class="mca-spin"></span> Cargando…</span>
                    </div>
                    @error('logo') <div class="mca-err" style="margin-top:8px">{{ $message }}</div> @enderror
                    <div class="mca-help" style="margin-top:10px">Recomendado: imagen horizontal con fondo transparente (PNG/SVG) para que se vea bien sobre el encabezado claro.</div>
                </div>
            </div>
        </div>

        {{-- Tamaño del logo (alto en px). La previsualización refleja el valor elegido. --}}
        @if ($logoUrl)
            <div class="card card-p fade" style="margin-top:16px">
                <h3 class="mca-h1" style="font-size:16px;margin-bottom:4px">Tamaño del logo</h3>
                <p class="mca-sub" style="margin-bottom:16px">Alto del logo en el encabezado, entre {{ \Modules\Institutions\Models\Institution::LOGO_SIZE_MIN }} y {{ \Modules\Institutions\Models\Institution::LOGO_SIZE_MAX }} px.</p>

                <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                    {{-- Previsualización a tamaño real sobre un fondo tipo sidebar --}}
                    <div style="min-width:180px;padding:14px 18px;border:1px solid var(--line);border-radius:12px;background:linear-gradient(180deg,#EEF2F7,#E7ECF4);display:flex;align-items:center">
                        <img src="{{ $logoUrl }}" alt="{{ $institution->name }}" style="max-height:{{ $logoSize }}px;max-width:200px;object-fit:contain">
                    </div>

                    <div style="flex:1;min-width:220px">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <div class="field" style="margin:0;width:110px">
                                <input type="number" wire:model.live="logoSize" min="{{ \Modules\Institutions\Models\Institution::LOGO_SIZE_MIN }}" max="{{ \Modules\Institutions\Models\Institution::LOGO_SIZE_MAX }}" step="1" aria-label="Alto del logo en píxeles">
                            </div>
                            <span class="mca-help">px</span>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('logoSize', 32)">Pequeño</button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('logoSize', 44)">Mediano</button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('logoSize', 64)">Grande</button>
                            <button type="button" wire:click="saveSize" class="btn btn-primary btn-sm"><x-ui.icon name="check" class="ic" style="width:15px;height:15px" /> Guardar tamaño</button>
                        </div>
                        @error('logoSize') <div class="mca-err" style="margin-top:8px">{{ $message }}</div> @enderror
                        <div class="mca-help" style="margin-top:10px">Valor actual: {{ $logoSize }} px. El sidebar mostrará el logo a este alto.</div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
