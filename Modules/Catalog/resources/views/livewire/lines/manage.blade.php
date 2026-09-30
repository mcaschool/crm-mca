<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <a href="{{ route('catalog.programs.index') }}" class="btn btn-ghost btn-sm" style="margin-bottom:14px">
            <x-ui.icon name="chevron-left" class="ic" style="width:15px;height:15px" /> {{ __('Volver al catálogo') }}
        </a>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div style="max-width:760px">
            <div style="font-size:12.5px;color:var(--mca-ink-3,#8A99B2);margin:0 0 12px">
                {{ __('Las categorías de formación (Microcredenciales, Programas Ejecutivos, Diplomas Avanzados…) son el tipo de programa. Son un eje distinto de las áreas temáticas.') }}
            </div>

            <div class="card card-p fade">
                <form wire:submit="save">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Nombre (ES)') }}</th>
                                <th>{{ __('Nombre (EN)') }}</th>
                                <th style="width:120px">{{ __('Estado') }}</th>
                                <th style="width:90px">{{ __('Orden') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $inp = 'width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:13.5px;font-family:inherit;color:var(--ink)'; @endphp
                            @forelse ($lines as $line)
                                <tr wire:key="line-{{ $line->id }}">
                                    <td>
                                        <input type="text" wire:model="rows.{{ $line->id }}.name_es" style="{{ $inp }}">
                                        @error('rows.'.$line->id.'.name_es') <span class="mca-err">{{ $message }}</span> @enderror
                                    </td>
                                    <td><input type="text" wire:model="rows.{{ $line->id }}.name_en" placeholder="{{ __('completar en inglés') }}" style="{{ $inp }}"></td>
                                    <td>
                                        <select wire:model="rows.{{ $line->id }}.status" style="{{ $inp }}">
                                            <option value="active">{{ __('Activa') }}</option>
                                            <option value="inactive">{{ __('Inactiva') }}</option>
                                        </select>
                                    </td>
                                    <td><input type="number" wire:model="rows.{{ $line->id }}.display_order" style="{{ $inp }}"></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="t-empty">{{ __('Aún no hay categorías de formación. Crea la primera abajo.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    @if ($lines->isNotEmpty())
                        <div style="margin-top:16px"><button type="submit" class="btn btn-primary btn-sm">{{ __('Guardar cambios') }}</button></div>
                    @endif
                </form>

                <div class="mca-section">
                    <form wire:submit="addLine" style="display:flex;align-items:flex-end;gap:12px">
                        <div class="field" style="flex:1;margin-bottom:0">
                            <label>{{ __('Nueva categoría de formación (ES)') }}</label>
                            <input type="text" wire:model="newNameEs" placeholder="{{ __('p. ej. Programas Ejecutivos') }}">
                            @error('newNameEs') <span class="mca-err">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Agregar') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
