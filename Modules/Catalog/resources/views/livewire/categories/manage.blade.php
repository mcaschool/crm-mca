<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Categorias del catalogo') }}</h2>
            <a href="{{ route('catalog.programs.index') }}" class="text-sm text-indigo-600 hover:underline">{{ __('Volver al catalogo') }}</a>
        </div>

        @if (session('status'))
            <div class="mb-4 text-sm text-green-700">{{ session('status') }}</div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form wire:submit="save" class="space-y-4">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="py-2">{{ __('Nombre (ES)') }}</th>
                            <th class="py-2">{{ __('Nombre (EN)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr wire:key="cat-{{ $category->id }}">
                                <td class="py-1 pe-3">
                                    <x-text-input type="text" class="block w-full" wire:model="rows.{{ $category->id }}.name_es" />
                                </td>
                                <td class="py-1">
                                    <x-text-input type="text" class="block w-full" wire:model="rows.{{ $category->id }}.name_en"
                                                  placeholder="{{ __('completar en ingles') }}" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($categories->isNotEmpty())
                    <x-primary-button>{{ __('Guardar cambios') }}</x-primary-button>
                @else
                    <p class="text-sm text-gray-500">{{ __('Aun no hay categorias.') }}</p>
                @endif
            </form>

            <hr class="my-6">

            <form wire:submit="addCategory" class="flex items-end gap-3">
                <div class="grow">
                    <x-input-label for="newNameEs" :value="__('Nueva categoria (ES)')" />
                    <x-text-input id="newNameEs" type="text" class="mt-1 block w-full" wire:model="newNameEs" />
                    <x-input-error :messages="$errors->get('newNameEs')" class="mt-2" />
                </div>
                <x-primary-button>{{ __('Agregar') }}</x-primary-button>
            </form>
        </div>
    </div>
</div>
