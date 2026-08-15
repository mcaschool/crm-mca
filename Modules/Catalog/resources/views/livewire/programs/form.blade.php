<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <h2 class="text-lg font-semibold mb-6">
                    {{ $editing ? __('Editar programa') : __('Nuevo programa') }}
                </h2>

                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="code" :value="__('Codigo')" />
                            <x-text-input id="code" type="text" class="mt-1 block w-full" wire:model="code" />
                            <x-input-error :messages="$errors->get('code')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="category_id" :value="__('Area / Categoria')" />
                            <select id="category_id" wire:model="category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">{{ __('— sin categoria —') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name_es }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Bilingue: espanol e ingles lado a lado. --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name_es" :value="__('Nombre (ES)')" />
                            <x-text-input id="name_es" type="text" class="mt-1 block w-full" wire:model="name_es" />
                            <x-input-error :messages="$errors->get('name_es')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="name_en" :value="__('Nombre (EN)')" />
                            <x-text-input id="name_en" type="text" class="mt-1 block w-full" wire:model="name_en"
                                          placeholder="{{ __('completar en ingles') }}" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="credential_en" :value="__('Microcredencial que otorga (EN)')" />
                        <x-text-input id="credential_en" type="text" class="mt-1 block w-full" wire:model="credential_en" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="level" :value="__('Nivel')" />
                            <x-text-input id="level" type="text" class="mt-1 block w-full" wire:model="level" placeholder="inicial/intermedio/avanzado" />
                        </div>
                        <div>
                            <x-input-label for="goal" :value="__('Meta')" />
                            <x-text-input id="goal" type="text" class="mt-1 block w-full" wire:model="goal" />
                        </div>
                        <div>
                            <x-input-label for="profile" :value="__('Perfil')" />
                            <x-text-input id="profile" type="text" class="mt-1 block w-full" wire:model="profile" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="duration_es" :value="__('Duracion (ES)')" />
                            <x-text-input id="duration_es" type="text" class="mt-1 block w-full" wire:model="duration_es" />
                        </div>
                        <div>
                            <x-input-label for="duration_en" :value="__('Duracion (EN)')" />
                            <x-text-input id="duration_en" type="text" class="mt-1 block w-full" wire:model="duration_en" />
                        </div>
                        <div>
                            <x-input-label for="modality_es" :value="__('Modalidad (ES)')" />
                            <x-text-input id="modality_es" type="text" class="mt-1 block w-full" wire:model="modality_es" />
                        </div>
                        <div>
                            <x-input-label for="modality_en" :value="__('Modalidad (EN)')" />
                            <x-text-input id="modality_en" type="text" class="mt-1 block w-full" wire:model="modality_en" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="short_description_es" :value="__('Descripcion corta (ES)')" />
                            <textarea id="short_description_es" wire:model="short_description_es" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea>
                        </div>
                        <div>
                            <x-input-label for="short_description_en" :value="__('Descripcion corta (EN)')" />
                            <textarea id="short_description_en" wire:model="short_description_en" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                                      placeholder="{{ __('completar en ingles') }}"></textarea>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="url" :value="__('URL (ficha en la web)')" />
                        <x-text-input id="url" type="text" class="mt-1 block w-full" wire:model="url" />
                        <x-input-error :messages="$errors->get('url')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="tagsCsv" :value="__('Etiquetas (separadas por coma; dominante-*, tema-*)')" />
                        <x-text-input id="tagsCsv" type="text" class="mt-1 block w-full" wire:model="tagsCsv" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="status" :value="__('Estado')" />
                            <select id="status" wire:model="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="active">{{ __('activo') }}</option>
                                <option value="inactive">{{ __('inactivo') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="display_order" :value="__('Orden (peso)')" />
                            <x-text-input id="display_order" type="number" class="mt-1 block w-full" wire:model="display_order" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                        <a href="{{ route('catalog.programs.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Cancelar') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
