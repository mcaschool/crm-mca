<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head" style="justify-content:flex-end">
            @if ($canManage)
                <a href="{{ route('users.create') }}" class="btn btn-primary"><x-ui.icon name="plus" /> Crear usuario</a>
            @endif
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- Buscar + filtros --}}
        <div class="card card-p fade" style="margin-bottom:16px">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                <div class="field" style="flex:2;min-width:200px;margin-bottom:0">
                    <label>Buscar</label>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Nombre o correo">
                </div>
                <div class="field" style="flex:1;min-width:150px;margin-bottom:0">
                    <label>Departamento</label>
                    <select wire:model.live="department">
                        <option value="">Todos</option>
                        @foreach ($departments as $dep)
                            <option value="{{ $dep }}">{{ $dep }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex:1;min-width:130px;margin-bottom:0">
                    <label>Estado</label>
                    <select wire:model.live="status">
                        <option value="">Todos</option>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card fade" style="overflow:hidden">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead>
                    <tr style="background:var(--paper);text-align:left;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px">
                        <th style="padding:12px 16px;font-weight:600">Nombre</th>
                        <th style="padding:12px 16px;font-weight:600">Correo</th>
                        <th style="padding:12px 16px;font-weight:600">Departamento</th>
                        <th style="padding:12px 16px;font-weight:600">Rol</th>
                        <th style="padding:12px 16px;font-weight:600">Estado</th>
                        <th style="padding:12px 16px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr wire:key="u-{{ $u->id }}" style="border-top:1px solid var(--line)">
                            <td style="padding:11px 16px">
                                <div style="display:flex;align-items:center;gap:11px">
                                    <span class="mca-av" style="width:38px;height:38px">
                                        @if ($u->avatarUrl())
                                            <img src="{{ $u->avatarUrl() }}" alt="{{ $u->name }}">
                                        @else
                                            <x-ui.icon name="user" class="ic" style="width:18px;height:18px" />
                                        @endif
                                    </span>
                                    <span style="font-weight:600;color:var(--ink)">{{ $u->name }}</span>
                                </div>
                            </td>
                            <td style="padding:13px 16px;color:var(--muted)">{{ $u->email }}</td>
                            <td style="padding:13px 16px">
                                @if ($u->department)
                                    <span class="badge badge-soft">{{ $u->department }}</span>
                                @else
                                    <span class="mca-muted">—</span>
                                @endif
                            </td>
                            <td style="padding:13px 16px;color:var(--muted)">{{ $u->role->label() }}</td>
                            <td style="padding:13px 16px">
                                <span class="badge {{ $u->status === 'active' ? 'badge-on' : 'badge-off' }}">{{ $u->status === 'active' ? 'Activo' : 'Inactivo' }}</span>
                            </td>
                            <td style="padding:10px 16px;text-align:right;white-space:nowrap">
                                @if ($canManage)
                                    <a href="{{ route('users.edit', $u->id) }}" class="btn btn-ghost btn-sm"><x-ui.icon name="pencil" class="ic" style="width:15px;height:15px" /> Editar</a>
                                    @if ($u->id !== auth()->id())
                                        <button type="button" wire:click="toggleActive({{ $u->id }})" class="btn btn-ghost btn-sm" title="{{ $u->status === 'active' ? 'Desactivar' : 'Activar' }}">
                                            <x-ui.icon name="power" class="ic" style="width:15px;height:15px" />
                                        </button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:40px;text-align:center;color:var(--muted)">Sin usuarios que coincidan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
