<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">

                <h2 class="text-lg font-semibold mb-1">
                    {{ __('Configurar') }}: {{ $meta['label'] }}
                </h2>
                <p class="text-xs text-gray-500 mb-6">
                    {{ __('Las credenciales se guardan cifradas. Una vez guardadas no se vuelven a mostrar: se enmascaran y solo pueden reemplazarse.') }}
                </p>

                <form wire:submit="save" class="space-y-6">
                    <div>
                        <x-input-label for="name" :value="__('Nombre')" />
                        <x-text-input id="name" type="text" class="mt-1 block w-full" wire:model="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    @if ($meta['providers'] !== [])
                        <div>
                            <x-input-label for="provider" :value="__('Proveedor')" />
                            <select id="provider" wire:model="provider"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                @foreach ($meta['providers'] as $p)
                                    <option value="{{ $p }}">{{ $p }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('provider')" class="mt-2" />
                        </div>
                    @endif

                    @foreach ($meta['fields'] as $field)
                        <div>
                            <x-input-label :for="$field['key']" :value="__($field['label'])" />

                            @if ($field['secret'] && ($maskedPreview[$field['key']] ?? null))
                                {{-- Barrera: el secreto actual solo se muestra ENMASCARADO, nunca en el input. --}}
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ __('Actual') }}: <span class="font-mono">{{ $maskedPreview[$field['key']] }}</span>
                                    — {{ __('deja el campo vacio para conservarla, o escribe una nueva para reemplazarla.') }}
                                </p>
                            @endif

                            @if ($field['type'] === 'select')
                                <select id="{{ $field['key'] }}" wire:model="inputs.{{ $field['key'] }}"
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    @foreach (($field['options'] ?? []) as $opt)
                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{-- Sin value=: los secretos empiezan vacios; se escriben para reemplazar. --}}
                                <x-text-input id="{{ $field['key'] }}"
                                    type="{{ $field['type'] === 'number' ? 'number' : ($field['secret'] ? 'password' : 'text') }}"
                                    class="mt-1 block w-full"
                                    wire:model="inputs.{{ $field['key'] }}"
                                    autocomplete="off"
                                    placeholder="{{ $field['secret'] ? __('Reemplazar credencial') : '' }}" />
                            @endif

                            <x-input-error :messages="$errors->get('inputs.'.$field['key'])" class="mt-2" />
                        </div>
                    @endforeach

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                        <a href="{{ route('integrations.index') }}" class="text-sm text-gray-600 hover:underline">
                            {{ __('Cancelar') }}
                        </a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
