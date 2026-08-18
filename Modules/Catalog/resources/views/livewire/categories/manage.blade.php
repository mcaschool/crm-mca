<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <a href="{{ route('catalog.programs.index') }}" class="btn btn-ghost btn-sm" style="margin-bottom:14px">
            <x-ui.icon name="chevron-left" class="ic" style="width:15px;height:15px" /> {{ __('Volver al catálogo') }}
        </a>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="card card-p fade" style="max-width:720px">
            <form wire:submit="save">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Nombre (ES)') }}</th>
                            <th>{{ __('Nombre (EN)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $category)
                            <tr wire:key="cat-{{ $category->id }}">
                                <td><input type="text" wire:model="rows.{{ $category->id }}.name_es" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:13.5px;font-family:inherit;color:var(--ink)"></td>
                                <td><input type="text" wire:model="rows.{{ $category->id }}.name_en" placeholder="{{ __('completar en inglés') }}" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:13.5px;font-family:inherit;color:var(--ink)"></td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="t-empty">{{ __('Aún no hay categorías.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($categories->isNotEmpty())
                    <div style="margin-top:16px"><button type="submit" class="btn btn-primary btn-sm">{{ __('Guardar cambios') }}</button></div>
                @endif
            </form>

            <div class="mca-section">
                <form wire:submit="addCategory" style="display:flex;align-items:flex-end;gap:12px">
                    <div class="field" style="flex:1;margin-bottom:0">
                        <label>{{ __('Nueva categoría (ES)') }}</label>
                        <input type="text" wire:model="newNameEs">
                        @error('newNameEs') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Agregar') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
