<div class="mca-page">
    <x-ui.page-header :title="__('Categorías')" :count="$categories->count()" :subtitle="__('Áreas de los programas de Microcredenciales, con su nombre en español y en inglés.')" :back="route('catalog.programs.index')" :back-label="__('Volver al catálogo')" />

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    <div class="ui-stack ui-narrow">
        <form class="ui-card" wire:submit="save">
            <div class="ui-card-h"><x-ui.icon name="layers" class="ui-card-ic" /><h2>{{ __('Nombres de las categorías') }}</h2></div>
            <div class="mca-table-scroll">
                <table class="ui-table ui-table--stack-cells ui-table--flat">
                    <thead>
                        <tr>
                            <th>{{ __('Nombre (ES)') }}</th>
                            <th>{{ __('Nombre (EN)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $category)
                            <tr wire:key="cat-{{ $category->id }}">
                                <td data-label="{{ __('Nombre (ES)') }}"><input type="text" class="ui-input ui-input--cell" wire:model="rows.{{ $category->id }}.name_es" aria-label="{{ __('Nombre (ES)') }} · {{ $category->name_es }}"></td>
                                <td data-label="{{ __('Nombre (EN)') }}"><input type="text" class="ui-input ui-input--cell" wire:model="rows.{{ $category->id }}.name_en" placeholder="{{ __('completar en inglés') }}" aria-label="{{ __('Nombre (EN)') }} · {{ $category->name_es }}"></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="rt-empty">
                                    <div class="ui-empty">
                                        <div class="ui-empty-ic"><x-ui.icon name="layers" /></div>
                                        <b>{{ __('Aún no hay categorías.') }}</b>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($categories->isNotEmpty())
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar cambios') }}</x-ui.button>
                </div>
            @endif
        </form>

        <form class="ui-card" wire:submit="addCategory">
            <div class="ui-card-h"><x-ui.icon name="plus" class="ui-card-ic" /><h2>{{ __('Nueva categoría') }}</h2></div>
            <div class="ui-card-p">
                <x-ui.field :label="__('Nueva categoría (ES)')" for="cat-new" required :error="$errors->first('newNameEs')" :help="__('El nombre en inglés se completa después en la lista de categorías.')">
                    <input id="cat-new" type="text" wire:model="newNameEs" maxlength="120" @class(['ui-input', 'is-invalid' => $errors->has('newNameEs')]) @error('newNameEs') aria-describedby="cat-new-error" @enderror>
                </x-ui.field>
            </div>
            <div class="ui-card-f ui-card-f--end">
                <x-ui.button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">{{ __('Agregar') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>
