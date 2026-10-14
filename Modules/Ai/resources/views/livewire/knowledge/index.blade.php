<div class="mca-page">
    <x-ui.page-header :title="__('Conocimiento')" :subtitle="__('Hechos transversales que el agente responde con sus palabras. Las reglas de conducta no se editan aquí.')">
        <x-slot:actions>
            <x-ui.button variant="primary" wire:click="sync" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sync" class="ui-btn-label"><x-ui.icon name="refresh" /> {{ __('Sincronizar') }}</span>
                <span wire:loading wire:target="sync"><span class="ui-spin"></span> {{ __('Sincronizando…') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stack">
        {{-- Aviso: esta vista es solo de consulta; la gestión completa está en el Centro. --}}
        <div class="ui-callout ui-tone--blue" role="note">
            <x-ui.icon name="book-open" />
            <span>
                <b>{{ __('Vista de solo consulta.') }}</b>
                {{ __('La gestión completa del conocimiento (subir, activar, borrar y asignar fuentes a agentes) está en el') }}
                <a href="{{ route('ai.knowledge.library') }}" class="ui-link">{{ __('Centro de Conocimiento') }}</a>.
            </span>
        </div>

        @if (session('status'))
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <div class="ui-card ui-card--list">
            <div class="ui-toolbar">
                <livewire:ai.advisor-selector />
            </div>
            <div class="mca-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('Código') }}</th>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Categoría') }}</th>
                            <th class="t-end">{{ __('Prioridad') }}</th>
                            <th>{{ __('Estado') }}</th>
                            <th>{{ __('Sincronizado') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sources as $source)
                            <tr wire:key="ks-{{ $source->id }}">
                                <td data-label="{{ __('Código') }}"><span class="ui-mono">{{ $source->code }}</span></td>
                                <td class="rt-primary rt-w-lg"><span class="ui-cell-title">{{ $source->name }}</span></td>
                                <td class="t-2" data-label="{{ __('Categoría') }}">{{ $source->category ?? '—' }}</td>
                                <td class="t-2 t-end" data-label="{{ __('Prioridad') }}">{{ $source->priority }}</td>
                                <td class="rt-aside" data-label="{{ __('Estado') }}">
                                    <x-ui.badge :tone="$source->status === 'active' ? 'success' : 'neutral'" dot>{{ $source->status === 'active' ? __('Activa') : ($source->status === 'inactive' ? __('Inactiva') : __($source->status)) }}</x-ui.badge>
                                </td>
                                <td class="t-2" data-label="{{ __('Sincronizado') }}">{{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : __('nunca') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="rt-empty">
                                    <div class="ui-empty">
                                        <div class="ui-empty-ic"><x-ui.icon name="book-open" /></div>
                                        {{ __('Sin fuentes de conocimiento. Coloca archivos .md en storage/app/knowledge y pulsa Sincronizar.') }}
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
