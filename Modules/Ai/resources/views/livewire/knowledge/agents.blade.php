@php
    $kcTotalActive = collect($lineBlocks)->sum('docs_active');
@endphp

<div class="mca-page">
    <x-ui.page-header :title="__('Centro de Conocimiento')" :subtitle="__('Elige un agente y decide qué fuentes de la biblioteca usa.')" />

    <div class="ui-stack">
        @include('ai::livewire.knowledge._tabs', ['active' => 'agents'])

        {{-- Selector de agente --}}
        <div class="ui-card">
            <div class="ui-toolbar ui-toolbar--flush">
                @if ($bot)
                    <x-ui.avatar :name="(string) $bot->assistant_name" square />
                @endif
                <livewire:ai.advisor-selector />
                @if ($bot)
                    <x-ui.badge tone="blue" dot>{{ __(':n fuentes activas', ['n' => $kcTotalActive]) }}</x-ui.badge>
                    <span class="sp"></span>
                    <span class="ui-help">{{ __('Las fuentes se gestionan en la pestaña Biblioteca; aquí solo se asignan.') }}</span>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        @if ($bot === null)
            <div class="ui-card">
                <div class="ui-empty">
                    <div class="ui-empty-ic"><x-ui.icon name="bot" /></div>
                    {{ __('No hay agentes activos. Crea o activa un asesor en «Asesores Inteligentes».') }}
                </div>
            </div>
        @elseif ($libraryEmpty)
            <div class="ui-card">
                <div class="ui-empty">
                    <div class="ui-empty-ic"><x-ui.icon name="book-open" /></div>
                    {{ __('La biblioteca está vacía. Sube fuentes .md en la pestaña Biblioteca.') }}
                </div>
            </div>
        @else
            {{-- Líneas de formación: por cada línea, las dos capas del agente (documentos de
                 conocimiento + programas que puede recomendar, con el área como subgrupo). --}}
            <div class="ui-section-h">
                <div>
                    <h2>{{ __('Líneas de formación') }}</h2>
                    <p>{{ __('Por cada línea: los documentos que :bot usa para responder y los programas que puede recomendar (su emparejador solo recomienda los asignados y activos).', ['bot' => $bot->assistant_name]) }}</p>
                </div>
                <x-ui.badge tone="blue" dot>{{ __(':n asignados', ['n' => $programAssignedTotal]) }}</x-ui.badge>
            </div>

            <div class="ui-card">
                <div class="ui-toolbar ui-toolbar--flush">
                    <x-ui.search wire:model.live.debounce.350ms="programSearch" placeholder="{{ __('Buscar programa por nombre o código (ej. PE-)…') }}" aria-label="{{ __('Buscar programa por nombre o código') }}" />
                    @if (trim($programSearch) !== '')
                        <span class="ui-card-sub" role="status">{{ trans_choice(':n resultado|:n resultados', $programMatches, ['n' => $programMatches]) }}</span>
                        @if ($programMatches > 0)
                            <span class="sp"></span>
                            <x-ui.button variant="primary" icon="check" wire:click="assignProgramResults" wire:loading.attr="disabled">{{ __('Asignar todos los resultados') }}</x-ui.button>
                            <x-ui.button icon="x" wire:click="detachProgramResults" wire:loading.attr="disabled">{{ __('Quitar todos los resultados') }}</x-ui.button>
                        @endif
                    @endif
                </div>
            </div>

            @foreach ($lineBlocks as $b)
                @php $noLine = $b['key'] === 'sin_linea'; @endphp
                <section class="ui-card" wire:key="line-{{ $b['key'] }}" aria-labelledby="kc-line-{{ $b['key'] }}">
                    <div class="ui-card-h">
                        <span @class(['ui-icon-tile', 'ui-tone--warning' => $noLine, 'ui-tone--blue' => ! $noLine])><x-ui.icon name="{{ $noLine ? 'alert-triangle' : 'layers' }}" /></span>
                        <div class="ui-person-text">
                            <h2 id="kc-line-{{ $b['key'] }}">{{ $b['label'] }}</h2>
                            <span class="ui-person-line">
                                <x-ui.badge :tone="$b['docs_active'] > 0 ? 'success' : 'neutral'" icon="file-text">{{ __('Documentos :a/:t', ['a' => $b['docs_active'], 't' => $b['docs_total']]) }}</x-ui.badge>
                                <x-ui.badge :tone="$b['programs_assigned'] > 0 ? 'success' : 'neutral'" icon="book-open">{{ __('Programas :a/:t', ['a' => $b['programs_assigned'], 't' => $b['programs_total']]) }}</x-ui.badge>
                            </span>
                        </div>
                        @if ($b['is_line'])
                            <span class="sp"></span>
                            <div class="ui-actions-row">
                                <x-ui.button size="sm" icon="check" wire:click="assignLine('{{ $b['key'] }}')" wire:loading.attr="disabled"
                                        wire:confirm="{{ __('¿Asignar a :bot la línea «:line» completa? Se activan todos sus documentos activos y se asignan todos sus programas activos.', ['bot' => $bot->assistant_name, 'line' => $b['label']]) }}">
                                    {{ __('Asignar línea completa') }}
                                </x-ui.button>
                                <x-ui.button size="sm" icon="x" wire:click="detachLine('{{ $b['key'] }}')" wire:loading.attr="disabled"
                                        wire:confirm="{{ __('¿Quitar a :bot la línea «:line» completa? Se quitan sus documentos activos y sus programas activos (no se borra nada de la biblioteca ni del catálogo).', ['bot' => $bot->assistant_name, 'line' => $b['label']]) }}">
                                    {{ __('Quitar línea completa') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>

                    {{-- Capa 1: documentos de conocimiento de la línea (plegados por defecto) --}}
                    <div class="ui-subhead ui-subhead--soft">
                        <button type="button" wire:click="toggleDocsOpen('{{ $b['key'] }}')" class="ui-fold" aria-expanded="{{ $b['docs_open'] ? 'true' : 'false' }}">
                            <x-ui.icon name="chevron-down" />
                            <span class="ui-subhead-title ui-subhead-title--strong">{{ __('Documentos de conocimiento') }}</span>
                            <x-ui.badge :tone="$b['docs_active'] > 0 ? 'success' : 'neutral'">{{ __('Documentos :a/:t', ['a' => $b['docs_active'], 't' => $b['docs_total']]) }}</x-ui.badge>
                        </button>
                        @if ($b['docs_open'] && $b['docs'] !== [])
                            <span class="sp"></span>
                            <button type="button" wire:click="toggleCategory('{{ $b['category_key'] }}')" wire:loading.attr="disabled"
                                class="ui-switch" role="switch" aria-checked="{{ $b['docs_full'] ? 'true' : 'false' }}">
                                <span class="ui-switch-track" aria-hidden="true"></span>
                                {{ __('Usar toda la categoría') }}
                            </button>
                        @endif
                    </div>
                    @if ($b['docs_open'])
                        <ul class="ui-list ui-list--indent ui-sep-top">
                            @forelse ($b['docs'] as $r)
                                <li class="ui-list-item ui-list-item--wrap" wire:key="src-{{ $r['id'] }}">
                                    <button type="button" wire:click="toggleSource({{ $r['id'] }})" class="ui-switch"
                                        role="switch" aria-checked="{{ $r['assigned'] === true ? 'true' : 'false' }}"
                                        title="{{ $r['assigned'] === true ? __('Pausar para este agente') : __('Activar para este agente') }}"
                                        aria-label="{{ $r['assigned'] === true ? __('Pausar para este agente') : __('Activar para este agente') }}">
                                        <span class="ui-switch-track" aria-hidden="true"></span>
                                    </button>
                                    <div class="ui-list-main">
                                        <span class="ui-list-title">{{ $r['name'] }}</span>
                                        <span class="ui-list-meta ui-mono">{{ $r['code'] }}</span>
                                    </div>
                                    <div class="ui-list-aside ui-list-aside--wrap">
                                        @if ($r['assigned'] === true)
                                            <x-ui.badge tone="success" dot>{{ __('Activa para :bot', ['bot' => $bot->assistant_name]) }}</x-ui.badge>
                                        @elseif ($r['assigned'] === false)
                                            <x-ui.badge tone="warning" dot>{{ __('Pausada') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge tone="neutral" dot>{{ __('No asignada') }}</x-ui.badge>
                                        @endif
                                        @if (! $r['global_active'])
                                            <x-ui.badge tone="danger" title="{{ __('Desactivada en la Biblioteca: ningún agente la usa') }}">{{ __('Inactiva global') }}</x-ui.badge>
                                        @endif
                                        @if ($r['shared_with'] !== [])
                                            <span class="ui-person-line ui-list-meta" title="{{ implode(', ', $r['shared_with']) }}">
                                                <span class="ui-avatars">
                                                    @foreach (array_slice($r['shared_with'], 0, 3) as $agentName)
                                                        <x-ui.avatar :name="$agentName" class="ui-avatar--sm" />
                                                    @endforeach
                                                </span>
                                                {{ __('Compartida con: :names', ['names' => implode(', ', $r['shared_with'])]) }}
                                            </span>
                                        @endif
                                        @if ($r['assigned'] !== null)
                                            <x-ui.button size="sm" icon="x" wire:click="detachSource({{ $r['id'] }})" title="{{ __('Quitar solo de este agente') }}">{{ __('Quitar') }}</x-ui.button>
                                        @endif
                                    </div>
                                </li>
                            @empty
                                <li class="ui-list-empty">{{ __('Sin documentos en esta línea.') }}</li>
                            @endforelse
                        </ul>
                    @endif

                    {{-- Capa 2: programas que puede recomendar (área como subgrupo solo en Microcredenciales) --}}
                    <div class="ui-subhead ui-subhead--soft">
                        <span class="ui-subhead-title ui-subhead-title--strong">{{ __('Programas que puede recomendar') }}</span>
                    </div>
                    @foreach ($b['areas'] as $a)
                        <div class="ui-subhead" wire:key="parea-{{ $b['key'] }}-{{ $a['key'] }}">
                            <button type="button" wire:click="toggleProgramAreaOpen('{{ $a['open_key'] }}')" class="ui-fold" aria-expanded="{{ $a['open'] ? 'true' : 'false' }}">
                                <x-ui.icon name="chevron-down" />
                                <span class="ui-subhead-title ui-subhead-title--strong">{{ $a['label'] }}</span>
                                <x-ui.badge :tone="$a['assigned_count'] > 0 ? 'success' : 'neutral'">
                                    {{ trim($programSearch) !== ''
                                        ? __(':a de :t resultados asignados', ['a' => $a['assigned_count'], 't' => $a['total']])
                                        : __(':a de :t asignados', ['a' => $a['assigned_count'], 't' => $a['total']]) }}
                                </x-ui.badge>
                            </button>
                            {{-- Con búsqueda, las acciones masivas son las de «resultados». --}}
                            <span class="sp"></span>
                            <div class="ui-actions-row">
                                @if (trim($programSearch) === '' && $a['assigned_count'] < $a['total'])
                                    <x-ui.button size="sm" icon="check" wire:click="assignProgramArea('{{ $a['key'] }}', '{{ $b['key'] }}')" wire:loading.attr="disabled">{{ __('Asignar toda el área') }}</x-ui.button>
                                @endif
                                @if (trim($programSearch) === '' && $a['assigned_count'] > 0)
                                    <x-ui.button size="sm" icon="x" wire:click="detachProgramArea('{{ $a['key'] }}', '{{ $b['key'] }}')" wire:loading.attr="disabled">{{ __('Quitar toda el área') }}</x-ui.button>
                                @endif
                            </div>
                        </div>

                        @if ($a['open'])
                            <ul class="ui-list ui-list--indent ui-sep-top" wire:key="parea-rows-{{ $b['key'] }}-{{ $a['key'] }}">
                                @foreach ($a['rows'] as $p)
                                    @include('ai::livewire.knowledge._program-row')
                                @endforeach
                            </ul>
                        @endif
                    @endforeach

                    {{-- Resto de líneas: lista plana por nombre, sin subgrupos de área --}}
                    @if ($b['rows'] !== [])
                        <ul class="ui-list ui-sep-top">
                            @foreach ($b['rows'] as $p)
                                @include('ai::livewire.knowledge._program-row')
                            @endforeach
                        </ul>
                    @endif

                    @if ($b['areas'] === [] && $b['rows'] === [])
                        <p class="ui-list-empty ui-sep-top">
                            {{ trim($programSearch) !== '' && $b['programs_total'] > 0 ? __('Ningún programa de esta línea coincide con la búsqueda.') : __('Sin programas en esta línea.') }}
                        </p>
                    @endif
                </section>
            @endforeach
        @endif
    </div>
</div>
