<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-toolbar">
            <select wire:model.live="lineFilter" style="max-width:230px">
                <option value="">{{ __('Todas las categorías de formación') }}</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">{{ $line->name_es }}</option>
                @endforeach
            </select>
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:13px;color:var(--ink);cursor:pointer;margin-left:6px">
                <input type="checkbox" wire:model.live="showArchived"> {{ __('Ver archivados') }}
            </label>
            <div class="sp"></div>
            <a href="{{ route('catalog.lines') }}" class="btn btn-ghost btn-sm">{{ __('Categorías de formación') }}</a>
            <a href="{{ route('catalog.categories') }}" class="btn btn-ghost btn-sm">{{ __('Áreas') }}</a>
            <a href="{{ route('catalog.programs.import') }}" class="btn btn-ghost btn-sm">
                <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> {{ __('Importar') }}
            </a>
            <a href="{{ route('catalog.programs.create') }}" class="btn btn-primary btn-sm">
                <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Nuevo programa') }}
            </a>
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        @if ($showArchived)
            <div style="font-size:12.5px;color:var(--muted);margin:0 0 12px"><x-ui.icon name="trash" class="ic" style="width:13px;height:13px" /> {{ __('Mostrando programas archivados. Puedes restaurarlos o eliminarlos definitivamente.') }}</div>
        @endif

        <div class="card" style="overflow:hidden">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Orden') }}</th>
                            <th>course_id</th>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Categoría de formación') }}</th>
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
                                    @if ($showArchived)
                                        <span class="t-strong">{{ $program->display_order }}</span>
                                    @else
                                        <div style="display:flex;align-items:center;gap:4px">
                                            <span class="t-strong">{{ $program->display_order }}</span>
                                            <button type="button" wire:click="moveUp({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;font-size:14px" title="{{ __('Subir') }}">↑</button>
                                            <button type="button" wire:click="moveDown({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;font-size:14px" title="{{ __('Bajar') }}">↓</button>
                                        </div>
                                    @endif
                                </td>
                                <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $program->course_idnumber ?: '—' }}</td>
                                <td class="t-strong">{{ $program->name_es }}</td>
                                <td class="t-mut">{{ optional($program->line)->name_es ?: '—' }}</td>
                                <td class="t-mut">{{ optional($program->category)->name_es ?: '—' }}</td>
                                <td class="t-mut" style="font-size:12.5px">
                                    {{ $program->level ?: '—' }} / {{ $program->goal ?: '—' }}
                                    @if (! $program->level && ! $program->goal && ! $program->profile)
                                        <span style="color:var(--mca-warn)" title="{{ __('Sin etiquetas: no aparece en el emparejador') }}">⚠</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($showArchived)
                                        <span class="badge badge-off">{{ __('archivado') }}</span>
                                    @else
                                        <span class="badge {{ $program->status === 'active' ? 'badge-on' : 'badge-off' }}">{{ $program->status === 'active' ? __('activo') : __('inactivo') }}</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap">
                                    @if ($showArchived)
                                        <button type="button" wire:click="restore({{ $program->id }})" style="color:var(--mca);font-weight:600;border:none;background:none;cursor:pointer">{{ __('Restaurar') }}</button>
                                        <button type="button" wire:click="confirmDelete({{ $program->id }})" style="color:#B23B3B;font-weight:600;border:none;background:none;cursor:pointer;margin-left:12px">{{ __('Eliminar definitivo') }}</button>
                                    @else
                                        <a href="{{ route('catalog.programs.edit', $program) }}" style="color:var(--mca);font-weight:600">{{ __('Editar') }}</a>
                                        <button type="button" wire:click="toggleActive({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;margin-left:12px;font-weight:600">
                                            {{ $program->status === 'active' ? __('Desactivar') : __('Activar') }}
                                        </button>
                                        <button type="button" wire:click="archive({{ $program->id }})" class="mca-muted" style="border:none;background:none;cursor:pointer;margin-left:12px;font-weight:600">{{ __('Archivar') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="t-empty">{{ $showArchived ? __('No hay programas archivados.') : __('Sin programas. Crea uno o importa un archivo.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Confirmación de ELIMINAR DEFINITIVO (no se borra de un clic) --}}
    @if ($deleting)
        <div style="position:fixed;inset:0;z-index:1000;background:rgba(19,37,61,.45);display:flex;align-items:center;justify-content:center;padding:24px" wire:key="del-{{ $deleting->id }}">
            <div class="card card-p" style="background:#fff;max-width:460px;width:100%">
                <div style="display:flex;align-items:flex-start;gap:12px">
                    <span style="flex:0 0 auto;width:38px;height:38px;border-radius:10px;background:#FBEAEA;color:#B23B3B;display:grid;place-items:center"><x-ui.icon name="alert-triangle" class="ic" style="width:20px;height:20px" /></span>
                    <div>
                        <div style="font-size:16px;font-weight:700;color:var(--ink)">{{ __('Eliminar definitivamente') }}</div>
                        <div style="font-size:13px;color:var(--muted);margin-top:6px">
                            {{ __('Vas a borrar') }} <b style="color:var(--ink)">{{ $deleting->name_es }}</b> ({{ $deleting->course_idnumber ?: '—' }}) {{ __('de la base de datos. Esta acción NO se puede deshacer.') }}
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                    <button type="button" wire:click="cancelDelete" class="btn btn-ghost btn-sm">{{ __('Cancelar') }}</button>
                    <button type="button" wire:click="deleteForever" class="btn btn-sm" style="background:#B23B3B;color:#fff;border-color:#B23B3B">
                        <x-ui.icon name="trash" class="ic" style="width:14px;height:14px" /> {{ __('Sí, eliminar definitivamente') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
