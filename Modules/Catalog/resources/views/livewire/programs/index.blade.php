<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-toolbar">
            <div class="sp"></div>
            <a href="{{ route('catalog.categories') }}" class="btn btn-ghost btn-sm">{{ __('Categorías') }}</a>
            <a href="{{ route('catalog.programs.create') }}" class="btn btn-primary btn-sm">
                <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Nuevo programa') }}
            </a>
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="card" style="overflow:hidden">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Orden') }}</th>
                            <th>{{ __('Código') }}</th>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Área') }}</th>
                            <th>{{ __('Nivel / Meta') }}</th>
                            <th>{{ __('Estado') }}</th>
                            <th>{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($programs as $program)
                            <tr wire:key="prog-{{ $program->id }}">
                                <td>
                                    <div style="display:flex;align-items:center;gap:4px">
                                        <span class="t-strong">{{ $program->display_order }}</span>
                                        <button type="button" wire:click="moveUp({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;font-size:14px" title="{{ __('Subir') }}">↑</button>
                                        <button type="button" wire:click="moveDown({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;font-size:14px" title="{{ __('Bajar') }}">↓</button>
                                    </div>
                                </td>
                                <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $program->code }}</td>
                                <td class="t-strong">{{ $program->name_es }}</td>
                                <td class="t-mut">{{ optional($program->category)->name_es }}</td>
                                <td class="t-mut" style="font-size:12.5px">
                                    {{ $program->level ?: '—' }} / {{ $program->goal ?: '—' }}
                                    @if (! $program->level && ! $program->goal && ! $program->profile)
                                        <span style="color:var(--mca-warn)" title="{{ __('Sin etiquetas: no aparece en el emparejador') }}">⚠</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $program->status === 'active' ? 'badge-on' : 'badge-off' }}">{{ $program->status === 'active' ? __('activo') : __('inactivo') }}</span>
                                </td>
                                <td style="white-space:nowrap">
                                    <a href="{{ route('catalog.programs.edit', $program) }}" style="color:var(--mca);font-weight:600">{{ __('Editar') }}</a>
                                    <button type="button" wire:click="toggleActive({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;margin-left:12px;font-weight:600">
                                        {{ $program->status === 'active' ? __('Desactivar') : __('Activar') }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="t-empty">{{ __('Sin programas. Importa el Excel con catalog:import o crea uno.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
