<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('catalog.programs.index') }}" class="btn btn-ghost btn-sm" title="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <h1 class="mca-h1">{{ $editing ? __('Editar programa') : __('Nuevo programa') }}</h1>
            </div>
        </div>

        <div class="card card-p fade">
            <form wire:submit="save">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Código') }}</label>
                        <input type="text" wire:model="code">
                        @error('code') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Área / Categoría') }}</label>
                        <select wire:model="category_id">
                            <option value="">{{ __('— sin categoría —') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name_es }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Nombre (ES)') }}</label>
                        <input type="text" wire:model="name_es">
                        @error('name_es') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Nombre (EN)') }}</label>
                        <input type="text" wire:model="name_en" placeholder="{{ __('completar en inglés') }}">
                    </div>
                </div>

                <div class="field" style="margin-top:16px">
                    <label>{{ __('Microcredencial que otorga (EN)') }}</label>
                    <input type="text" wire:model="credential_en">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Nivel') }}</label>
                        <input type="text" wire:model="level" placeholder="inicial/intermedio/avanzado">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Meta') }}</label>
                        <input type="text" wire:model="goal">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Perfil') }}</label>
                        <input type="text" wire:model="profile">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Duración (ES)') }}</label>
                        <input type="text" wire:model="duration_es">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Duración (EN)') }}</label>
                        <input type="text" wire:model="duration_en">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Modalidad (ES)') }}</label>
                        <input type="text" wire:model="modality_es">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Modalidad (EN)') }}</label>
                        <input type="text" wire:model="modality_en">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Descripción corta (ES)') }}</label>
                        <textarea wire:model="short_description_es" rows="3"></textarea>
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Descripción corta (EN)') }}</label>
                        <textarea wire:model="short_description_en" rows="3" placeholder="{{ __('completar en inglés') }}"></textarea>
                    </div>
                </div>

                <div class="field" style="margin-top:16px">
                    <label>{{ __('URL (ficha en la web)') }}</label>
                    <input type="text" wire:model="url">
                    @error('url') <span class="mca-err">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>{{ __('Etiquetas (separadas por coma; dominante-*, tema-*)') }}</label>
                    <input type="text" wire:model="tagsCsv">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Estado') }}</label>
                        <select wire:model="status">
                            <option value="active">{{ __('activo') }}</option>
                            <option value="inactive">{{ __('inactivo') }}</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Orden (peso)') }}</label>
                        <input type="number" wire:model="display_order" style="width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:14px;font-family:inherit;color:var(--ink);background:#fff">
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:12px;margin-top:20px">
                    <button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button>
                    <a href="{{ route('catalog.programs.index') }}" class="btn btn-ghost">{{ __('Cancelar') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
