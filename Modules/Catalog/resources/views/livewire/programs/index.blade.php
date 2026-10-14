<div class="mca-page">
    <x-ui.page-header :title="__('Catálogo')" :count="$programs->count()" :subtitle="__('Programas que el emparejador y los asesores pueden recomendar.')">
        <x-slot:actions>
            <x-ui.button icon="layers" :href="route('catalog.categories')">{{ __('Categorías') }}</x-ui.button>
            <x-ui.button variant="primary" icon="plus" :href="route('catalog.programs.create')">{{ __('Nuevo programa') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    <div class="ui-card ui-card--list">
        <div class="mca-table-scroll">
        <table class="ui-table">
            <thead>
                <tr>
                    <th class="rt-sticky">{{ __('Programa') }}</th>
                    <th>{{ __('Estado') }}</th>
                    <th>{{ __('Área') }}</th>
                    <th>{{ __('Nivel / Meta') }}</th>
                    <th>{{ __('Orden') }}</th>
                    <th><span class="ui-sr">{{ __('Acciones') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($programs as $program)
                    @php $active = $program->status === 'active'; @endphp
                    <tr wire:key="prog-{{ $program->id }}">
                        <td class="rt-primary rt-sticky rt-w-lg">
                            <div class="ui-person-text">
                                <span class="ui-cell-title">{{ $program->name_es }}</span>
                                <span class="ui-cell-sub ui-mono">{{ $program->code }}</span>
                            </div>
                        </td>
                        <td class="rt-aside" data-label="{{ __('Estado') }}">
                            <x-ui.badge :tone="$active ? 'success' : 'neutral'" dot>{{ Str::ucfirst($active ? __('activo') : __('inactivo')) }}</x-ui.badge>
                        </td>
                        {{-- Las áreas solo existen en Microcredenciales. --}}
                        <td class="rt-w-sm t-2" data-label="{{ __('Área') }}">
                            @if (\Modules\Catalog\Models\Program::lineHasAreas($program->line) && $program->category)
                                {{ $program->category->name_es }}
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td class="rt-w-sm t-2" data-label="{{ __('Nivel / Meta') }}">
                            <div>
                                {{ $program->level ?: '—' }} / {{ $program->goal ?: '—' }}
                                @if (! $program->level && ! $program->goal && ! $program->profile)
                                    <span class="t-sub"><x-ui.badge tone="warning" icon="alert-triangle" title="{{ __('Sin etiquetas: no aparece en el emparejador') }}">{{ __('Sin etiquetas') }}</x-ui.badge></span>
                                @endif
                            </div>
                        </td>
                        <td class="rt-foot" data-label="{{ __('Orden') }}">
                            <div class="t-order">
                                <span class="t-order-n">{{ $program->display_order }}</span>
                                <x-ui.button variant="ghost" size="sm" icon="chevron-up" wire:click="moveUp({{ $program->id }})" :aria-label="__('Subir').' '.$program->name_es" :title="__('Subir')" />
                                <x-ui.button variant="ghost" size="sm" icon="chevron-down" wire:click="moveDown({{ $program->id }})" :aria-label="__('Bajar').' '.$program->name_es" :title="__('Bajar')" />
                            </div>
                        </td>
                        <td class="rt-actions">
                            <div class="t-actions">
                                <x-ui.button size="sm" icon="pencil" :href="route('catalog.programs.edit', $program)">{{ __('Editar') }}</x-ui.button>
                                <x-ui.button size="sm" icon="power" wire:click="toggleActive({{ $program->id }})" :title="$active ? __('Desactivar') : __('Activar')" :aria-label="($active ? __('Desactivar') : __('Activar')).' '.$program->name_es"
                                    wire:confirm="{{ $active ? __('¿Desactivar el programa «:name»? Dejará de ofrecerse en el chat, el emparejador y los asesores.', ['name' => $program->name_es]) : __('¿Activar el programa «:name»? Volverá a ofrecerse en el chat, el emparejador y los asesores.', ['name' => $program->name_es]) }}" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="rt-empty">
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="book-open" /></div>
                                <b>{{ __('Sin programas. Importa el Excel con catalog:import o crea uno.') }}</b>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
