<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">

                <h2 class="text-lg font-semibold mb-6">
                    {{ $editing ? __('Editar usuario') : __('Nuevo usuario') }}
                </h2>

                <form wire:submit="save" class="space-y-6">
                    <div>
                        <x-input-label for="name" :value="__('Nombre')" />
                        <x-text-input id="name" type="text" class="mt-1 block w-full" wire:model="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" :value="__('Correo')" />
                        <x-text-input id="email" type="email" class="mt-1 block w-full" wire:model="email" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="role" :value="__('Rol')" />
                        <select id="role" wire:model="role"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach ($roles as $r)
                                <option value="{{ $r->value }}">{{ $r->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>

                    @if ($canGrantSuperAdmin)
                        <label class="flex items-center">
                            <input type="checkbox" wire:model="is_super_admin"
                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <span class="ms-2 text-sm text-gray-600">{{ __('Super-administrador (todas las instituciones)') }}</span>
                        </label>
                    @endif

                    <div>
                        <x-input-label for="password"
                            :value="$editing ? __('Nueva contrasena (opcional)') : __('Contrasena')" />
                        <x-text-input id="password" type="password" class="mt-1 block w-full" wire:model="password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                        <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline">
                            {{ __('Cancelar') }}
                        </a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
