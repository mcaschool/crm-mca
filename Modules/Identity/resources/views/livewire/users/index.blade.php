<div class="mca-page">
    <x-ui.page-header :title="__('Usuarios')" :count="$users->count()" :subtitle="__('Personas con acceso al panel, su rol y su estado.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" :href="route('users.create')">Crear usuario</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    <div class="ui-card ui-card--list">
        <div class="ui-toolbar">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar por nombre o correo…" aria-label="Buscar por nombre o correo" />

            <x-ui.select filter :active="$department !== ''" wire:model.live="department" aria-label="Filtrar por departamento">
                <option value="">Departamento · todos</option>
                @foreach ($departments as $dep)
                    <option value="{{ $dep }}">{{ $dep }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select filter :active="$status !== ''" wire:model.live="status" aria-label="Filtrar por estado">
                <option value="">Estado · todos</option>
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
            </x-ui.select>
        </div>

        <div class="mca-table-scroll">
        <table class="ui-table">
            <thead>
                <tr>
                    <th class="rt-sticky">Usuario</th><th>Estado</th><th>Rol</th><th>Departamento</th>
                    <th><span class="ui-sr">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    @php $active = $u->status === 'active'; @endphp
                    <tr wire:key="u-{{ $u->id }}">
                        <td class="rt-primary rt-sticky">
                            <div class="ui-person">
                                <x-ui.avatar :name="$u->name" :src="$u->avatarUrl()" :seed="$u->id" />
                                <div class="ui-person-text">
                                    <div class="ui-person-line">
                                        <span class="ui-person-name">{{ $u->name }}</span>
                                        @if ($u->id === auth()->id())<x-ui.badge tone="blue">Tú</x-ui.badge>@endif
                                    </div>
                                    <span class="ui-person-mail">{!! str_replace('@', '@<wbr>', e($u->email)) !!}</span>
                                </div>
                            </div>
                        </td>
                        <td class="rt-aside" data-label="Estado">
                            <x-ui.badge :tone="$active ? 'success' : 'neutral'" dot>{{ $active ? 'Activo' : 'Inactivo' }}</x-ui.badge>
                        </td>
                        <td data-label="Rol">{{ $u->role->label() }}</td>
                        <td data-label="Departamento">
                            @if ($u->department)
                                <span class="t-2">{{ $u->department }}</span>
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td @class(['rt-actions' => $canManage])>
                            @if ($canManage)
                                <div class="t-actions">
                                    <x-ui.button size="sm" icon="pencil" :href="route('users.edit', $u->id)">Editar</x-ui.button>
                                    @if ($u->id !== auth()->id())
                                        <x-ui.button size="sm" icon="power" wire:click="toggleActive({{ $u->id }})"
                                            title="{{ $active ? 'Desactivar' : 'Activar' }}" aria-label="{{ ($active ? 'Desactivar' : 'Activar').' '.$u->name }}" />
                                    @else
                                        <span class="t-gap" aria-hidden="true"></span>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="rt-empty">
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="users" /></div>
                                <b>Sin usuarios que coincidan.</b>
                                Prueba con otra búsqueda o quita algún filtro.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
