<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">

                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold">{{ __('Usuarios del panel') }}</h2>
                    <a href="{{ route('users.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Nuevo usuario') }}
                    </a>
                </div>

                @if (session('status'))
                    <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <th class="px-4 py-2">{{ __('Nombre') }}</th>
                                <th class="px-4 py-2">{{ __('Correo') }}</th>
                                <th class="px-4 py-2">{{ __('Rol') }}</th>
                                <th class="px-4 py-2">{{ __('Estado') }}</th>
                                <th class="px-4 py-2">{{ __('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($users as $user)
                                <tr wire:key="user-{{ $user->id }}">
                                    <td class="px-4 py-2">
                                        {{ $user->name }}
                                        @if ($user->is_super_admin)
                                            <span class="ml-1 text-xs text-indigo-600">({{ __('super-admin') }})</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $user->email }}</td>
                                    <td class="px-4 py-2">{{ $user->role->label() }}</td>
                                    <td class="px-4 py-2">
                                        <span @class([
                                            'text-xs px-2 py-1 rounded',
                                            'bg-green-100 text-green-800' => $user->isActive(),
                                            'bg-gray-200 text-gray-600' => ! $user->isActive(),
                                        ])>{{ $user->isActive() ? __('activo') : __('inactivo') }}</span>
                                    </td>
                                    <td class="px-4 py-2 space-x-3">
                                        <a href="{{ route('users.edit', $user) }}"
                                           class="text-indigo-600 hover:underline">{{ __('Editar') }}</a>
                                        @can('deactivate', $user)
                                            <button type="button" wire:click="toggleActive({{ $user->id }})"
                                                    class="text-gray-600 hover:underline">
                                                {{ $user->isActive() ? __('Desactivar') : __('Activar') }}
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
