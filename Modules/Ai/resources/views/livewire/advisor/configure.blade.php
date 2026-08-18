<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-8">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Ficha del Asesor Académico') }}</h2>
            <p class="text-sm text-gray-500">{{ __('Nombre, foto y bases de conocimiento del asesor. El widget y los saludos leen estos valores.') }}</p>
        </div>

        @if (session('status'))
            <div class="text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">{{ session('status') }}</div>
        @endif

        @if ($bot === null)
            <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded p-3">
                {{ __('No hay un bot activo que configurar.') }}
            </div>
        @else
        {{-- 1) Identidad: nombre + avatar --}}
        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('Identidad') }}</h3>
            <div class="flex flex-col sm:flex-row gap-6">
                {{-- Avatar --}}
                <div class="flex flex-col items-center gap-3">
                    <div class="w-24 h-24 rounded-full overflow-hidden bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                        @if ($avatar)
                            <img src="{{ $avatar->temporaryUrl() }}" alt="preview" class="w-full h-full object-cover">
                        @elseif ($avatarUrl)
                            <img src="{{ $avatarUrl }}" alt="{{ $bot->assistant_name }}" class="w-full h-full object-cover">
                        @else
                            {{-- Avatar por defecto: icono graduation-cap (Lucide) --}}
                            <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/>
                                <path d="M22 10v6"/>
                                <path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>
                            </svg>
                        @endif
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <label class="text-xs px-3 py-1.5 bg-gray-800 text-white rounded cursor-pointer hover:bg-gray-700">
                            {{ __('Elegir foto') }}
                            <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                        </label>
                        <div wire:loading wire:target="avatar" class="text-xs text-gray-400">{{ __('Cargando…') }}</div>
                        @error('avatar') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        <div class="flex gap-2 mt-1">
                            @if ($avatar)
                                <button type="button" wire:click="saveAvatar" class="text-xs px-3 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-500">{{ __('Guardar foto') }}</button>
                            @endif
                            @if ($avatarUrl)
                                <button type="button" wire:click="removeAvatar" class="text-xs px-3 py-1.5 border border-gray-300 text-gray-600 rounded hover:bg-gray-50">{{ __('Quitar') }}</button>
                            @endif
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 text-center max-w-[10rem]">{{ __('PNG, JPG, SVG o WebP · máx 1 MB') }}</p>
                </div>

                {{-- Nombre --}}
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Nombre del asesor') }}</label>
                    <input type="text" wire:model="name" maxlength="60"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    <button type="button" wire:click="saveName"
                            class="mt-3 text-sm px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-500">{{ __('Guardar nombre') }}</button>
                    <p class="text-xs text-gray-400 mt-3">
                        {{ __('Este nombre aparece en el encabezado del widget, junto a cada respuesta y en el saludo.') }}
                    </p>
                </div>
            </div>
        </div>

        {{-- 2) Bases de conocimiento --}}
        <div class="bg-white shadow-sm rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-semibold text-gray-900">{{ __('Bases de conocimiento') }}</h3>
                    <p class="text-sm text-gray-500">{{ __('Hechos que el asesor responde. Al subir un .md se actualiza por código y se sincroniza solo.') }}</p>
                </div>
                <button type="button" wire:click="sync" wire:loading.attr="disabled"
                        class="text-sm px-3 py-2 border border-gray-300 text-gray-700 rounded hover:bg-gray-50">
                    <span wire:loading.remove wire:target="sync">{{ __('Re-sincronizar') }}</span>
                    <span wire:loading wire:target="sync">{{ __('Sincronizando…') }}</span>
                </button>
            </div>

            <div class="border border-dashed border-gray-300 rounded-lg p-4 mb-4">
                <label class="text-xs px-3 py-1.5 bg-gray-800 text-white rounded cursor-pointer hover:bg-gray-700">
                    {{ __('Elegir archivos .md') }}
                    <input type="file" wire:model="docs" accept=".md" multiple class="hidden">
                </label>
                <span wire:loading wire:target="docs" class="text-xs text-gray-400 ml-2">{{ __('Cargando…') }}</span>
                @error('docs') <span class="text-xs text-red-600 ml-2">{{ $message }}</span> @enderror

                @if (count($docs))
                    <ul class="mt-3 text-xs text-gray-600 list-disc list-inside">
                        @foreach ($docs as $d)
                            <li>{{ $d->getClientOriginalName() }}</li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="uploadKnowledge"
                            class="mt-3 text-sm px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-500">{{ __('Subir y sincronizar') }}</button>
                @endif
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Código') }}</th>
                        <th class="px-4 py-3">{{ __('Nombre') }}</th>
                        <th class="px-4 py-3">{{ __('Estado') }}</th>
                        <th class="px-4 py-3">{{ __('Última sincronización') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sources as $source)
                        <tr wire:key="ks-{{ $source->id }}">
                            <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $source->code }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ $source->name }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'text-xs px-2 py-1 rounded',
                                    'bg-green-100 text-green-800' => $source->status === 'active',
                                    'bg-gray-200 text-gray-600' => $source->status !== 'active',
                                ])>{{ __($source->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : __('nunca') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-400">
                                {{ __('Sin documentos. Sube uno o varios .md para cargar el conocimiento del asesor.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
