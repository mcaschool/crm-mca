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
                        <label>{{ __('course_id (Moodle)') }} @if (! $editing)<span style="color:#B23B3B">*</span>@endif</label>
                        @if ($editing)
                            <input type="text" value="{{ $course_idnumber }}" disabled style="background:#F2F5F9;color:var(--mca-ink-2,#5A6B84)">
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--muted);margin-top:5px">
                                <x-ui.icon name="lock" class="ic" style="width:12px;height:12px" /> {{ __('Bloqueado: cambiarlo rompería el vínculo con Moodle y los leads InCompany.') }}
                            </span>
                        @else
                            <input type="text" wire:model="course_idnumber" placeholder="{{ __('p. ej. mgecc') }}">
                            @error('course_idnumber') <span class="mca-err">{{ $message }}</span> @enderror
                        @endif
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Área temática') }}</label>
                        <select wire:model="category_id">
                            <option value="">{{ __('— sin área —') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name_es }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Categoría de formación') }}</label>
                        <select wire:model="line_id">
                            <option value="">{{ __('— sin categoría de formación —') }}</option>
                            @foreach ($lines as $line)
                                <option value="{{ $line->id }}">{{ $line->name_es }}</option>
                            @endforeach
                        </select>
                        @error('line_id') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('… o crear una categoría de formación nueva') }}</label>
                        <input type="text" wire:model="newLineName" placeholder="{{ __('opcional; p. ej. Diplomas Avanzados') }}">
                        @error('newLineName') <span class="mca-err">{{ $message }}</span> @enderror
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
                        <label>{{ __('Nivel') }} <span style="color:var(--muted);font-weight:400">({{ __('recomendador') }})</span></label>
                        <select wire:model="level">
                            <option value="">{{ __('— sin nivel —') }}</option>
                            @foreach ($levels as $lvl)
                                <option value="{{ $lvl }}">{{ ucfirst($lvl) }}</option>
                            @endforeach
                        </select>
                        @error('level') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Meta') }} <span style="color:var(--muted);font-weight:400">({{ __('recomendador') }})</span></label>
                        <select wire:model="goal">
                            <option value="">{{ __('— sin meta —') }}</option>
                            @foreach ($goals as $g)
                                <option value="{{ $g }}">{{ ucfirst($g) }}</option>
                            @endforeach
                        </select>
                        @error('goal') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Perfil') }} <span style="color:var(--muted);font-weight:400">({{ __('recomendador') }})</span></label>
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

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Aprendizajes (ES)') }}</label>
                        <textarea wire:model="learnings_es" rows="3"></textarea>
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>{{ __('Aprendizajes (EN)') }}</label>
                        <textarea wire:model="learnings_en" rows="3" placeholder="{{ __('completar en inglés') }}"></textarea>
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
