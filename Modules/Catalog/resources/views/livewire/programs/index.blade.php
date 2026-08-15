<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Catalogo de programas') }}</h2>
            <div class="flex gap-3">
                <a href="{{ route('catalog.categories') }}" class="text-sm text-indigo-600 hover:underline">{{ __('Categorias') }}</a>
                <a href="{{ route('catalog.programs.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    {{ __('Nuevo programa') }}
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">{{ session('status') }}</div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase">
                        <th class="px-3 py-2">{{ __('Orden') }}</th>
                        <th class="px-3 py-2">{{ __('Codigo') }}</th>
                        <th class="px-3 py-2">{{ __('Nombre') }}</th>
                        <th class="px-3 py-2">{{ __('Area') }}</th>
                        <th class="px-3 py-2">{{ __('Nivel / Meta') }}</th>
                        <th class="px-3 py-2">{{ __('Estado') }}</th>
                        <th class="px-3 py-2">{{ __('Acciones') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($programs as $program)
                        <tr wire:key="prog-{{ $program->id }}">
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-1">
                                    <span>{{ $program->display_order }}</span>
                                    <button type="button" wire:click="moveUp({{ $program->id }})" class="text-gray-400 hover:text-gray-700">↑</button>
                                    <button type="button" wire:click="moveDown({{ $program->id }})" class="text-gray-400 hover:text-gray-700">↓</button>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $program->code }}</td>
                            <td class="px-3 py-2">{{ $program->name_es }}</td>
                            <td class="px-3 py-2">{{ optional($program->category)->name_es }}</td>
                            <td class="px-3 py-2 text-xs">
                                {{ $program->level ?: '—' }} / {{ $program->goal ?: '—' }}
                                @if (! $program->level && ! $program->goal && ! $program->profile)
                                    <span class="text-red-500" title="{{ __('Sin etiquetas: no aparece en el emparejador') }}">⚠</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'text-xs px-2 py-1 rounded',
                                    'bg-green-100 text-green-800' => $program->status === 'active',
                                    'bg-gray-200 text-gray-600' => $program->status !== 'active',
                                ])>{{ $program->status === 'active' ? __('activo') : __('inactivo') }}</span>
                            </td>
                            <td class="px-3 py-2 space-x-3 whitespace-nowrap">
                                <a href="{{ route('catalog.programs.edit', $program) }}" class="text-indigo-600 hover:underline">{{ __('Editar') }}</a>
                                <button type="button" wire:click="toggleActive({{ $program->id }})" class="text-gray-600 hover:underline">
                                    {{ $program->status === 'active' ? __('Desactivar') : __('Activar') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($programs->isEmpty())
                <p class="text-sm text-gray-500 p-4">{{ __('Sin programas. Importa el Excel con catalog:import o crea uno.') }}</p>
            @endif
        </div>
    </div>
</div>
