@php
    use Illuminate\Support\Str;

    // Color de avatar estable por agente (misma paleta que la Biblioteca).
    $kcPalette = [
        ['bg' => 'var(--mca-gold-soft)', 'fg' => 'var(--mca-gold)'],
        ['bg' => 'var(--mca-blue-soft)', 'fg' => 'var(--mca-blue)'],
        ['bg' => 'var(--mca-ok-soft)', 'fg' => 'var(--mca-ok)'],
        ['bg' => 'var(--mca-warn-soft)', 'fg' => 'var(--mca-warn)'],
        ['bg' => '#F1EEF9', 'fg' => '#6D5AB8'],
    ];
    $kcAvatar = fn (string $name): array => $kcPalette[abs(crc32($name)) % count($kcPalette)];
    $kcTotalActive = collect($lineBlocks)->sum('docs_active');
@endphp

<div>
    <x-ui.styles />
    @include('ai::livewire.knowledge._styles')

    <div class="mca-panel kc">
        <div class="kc-header">
            <div>
                <h1 class="kc-title">{{ __('Centro de Conocimiento') }}</h1>
                <p class="kc-sub">{{ __('Elige un agente y decide qué fuentes de la biblioteca usa.') }}</p>
            </div>
        </div>

        @include('ai::livewire.knowledge._tabs')

        {{-- Selector de agente --}}
        <div class="kc-card kc-picker">
            @if ($bot)
                @php $col = $kcAvatar((string) $bot->assistant_name); @endphp
                <span class="kc-picker-av" style="background:{{ $col['bg'] }};color:{{ $col['fg'] }}">{{ Str::upper(Str::substr((string) $bot->assistant_name, 0, 1)) }}</span>
            @endif
            <livewire:ai.advisor-selector />
            @if ($bot)
                <span class="kc-badge t-blue"><span class="kc-dot"></span>{{ __(':n fuentes activas', ['n' => $kcTotalActive]) }}</span>
                <span class="kc-picker-hint">{{ __('Las fuentes se gestionan en la pestaña Biblioteca; aquí solo se asignan.') }}</span>
            @endif
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        @if ($bot === null)
            <div class="kc-card kc-empty">
                <span class="kc-ic t-blue"><x-ui.icon name="bot" /></span>
                <p>{{ __('No hay agentes activos. Crea o activa un asesor en «Asesores Inteligentes».') }}</p>
            </div>
        @elseif ($libraryEmpty)
            <div class="kc-card kc-empty">
                <span class="kc-ic t-blue"><x-ui.icon name="book-open" /></span>
                <p>{{ __('La biblioteca está vacía. Sube fuentes .md en la pestaña Biblioteca.') }}</p>
            </div>
        @else
            {{-- Líneas de formación: por cada línea, las dos capas del agente (documentos de
                 conocimiento + programas que puede recomendar, con el área como subgrupo). --}}
            <div class="kc-section-head">
                <div>
                    <h2 class="kc-section-title">{{ __('Líneas de formación') }}</h2>
                    <p class="kc-section-sub">{{ __('Por cada línea: los documentos que :bot usa para responder y los programas que puede recomendar (su emparejador solo recomienda los asignados y activos).', ['bot' => $bot->assistant_name]) }}</p>
                </div>
                <span class="kc-badge t-blue"><span class="kc-dot"></span>{{ __(':n asignados', ['n' => $programAssignedTotal]) }}</span>
            </div>

            <div class="kc-toolbar kc-prog-tools">
                <div class="kc-search">
                    <x-ui.icon name="search" />
                    <input type="text" wire:model.live.debounce.350ms="programSearch" placeholder="{{ __('Buscar programa por nombre o código (ej. PE-)…') }}" aria-label="{{ __('Buscar programa por nombre o código') }}">
                </div>
                @if (trim($programSearch) !== '')
                    <span class="kc-prog-count">{{ trans_choice(':n resultado|:n resultados', $programMatches, ['n' => $programMatches]) }}</span>
                    @if ($programMatches > 0)
                        <button type="button" wire:click="assignProgramResults" wire:loading.attr="disabled" class="kc-btn kc-btn-primary">
                            <x-ui.icon name="check" /> {{ __('Asignar todos los resultados') }}
                        </button>
                        <button type="button" wire:click="detachProgramResults" wire:loading.attr="disabled" class="kc-btn kc-btn-ghost">
                            <x-ui.icon name="x" /> {{ __('Quitar todos los resultados') }}
                        </button>
                    @endif
                @endif
            </div>

            @foreach ($lineBlocks as $b)
                @php $noLine = $b['key'] === 'sin_linea'; @endphp
                <div class="kc-card kc-line" wire:key="line-{{ $b['key'] }}">
                    <div class="kc-line-head">
                        <span @class(['kc-ic', 't-amber' => $noLine, 't-blue' => ! $noLine])><x-ui.icon name="{{ $noLine ? 'alert-triangle' : 'layers' }}" /></span>
                        <div class="kc-line-titles">
                            <span class="kc-line-title">{{ $b['label'] }}</span>
                            <span class="kc-line-counters">
                                <span @class(['kc-badge', 't-green' => $b['docs_active'] > 0, 't-gray' => $b['docs_active'] === 0])>
                                    <x-ui.icon name="file-text" /> {{ __('Documentos :a/:t', ['a' => $b['docs_active'], 't' => $b['docs_total']]) }}
                                </span>
                                <span @class(['kc-badge', 't-green' => $b['programs_assigned'] > 0, 't-gray' => $b['programs_assigned'] === 0])>
                                    <x-ui.icon name="book-open" /> {{ __('Programas :a/:t', ['a' => $b['programs_assigned'], 't' => $b['programs_total']]) }}
                                </span>
                            </span>
                        </div>
                        @if ($b['is_line'])
                            <div class="kc-line-actions">
                                <button type="button" wire:click="assignLine('{{ $b['key'] }}')" wire:loading.attr="disabled"
                                        wire:confirm="{{ __('¿Asignar a :bot la línea «:line» completa? Se activan todos sus documentos activos y se asignan todos sus programas activos.', ['bot' => $bot->assistant_name, 'line' => $b['label']]) }}"
                                        class="kc-btn kc-btn-primary">
                                    <x-ui.icon name="check" /> {{ __('Asignar línea completa') }}
                                </button>
                                <button type="button" wire:click="detachLine('{{ $b['key'] }}')" wire:loading.attr="disabled"
                                        wire:confirm="{{ __('¿Quitar a :bot la línea «:line» completa? Se quitan sus documentos activos y sus programas activos (no se borra nada de la biblioteca ni del catálogo).', ['bot' => $bot->assistant_name, 'line' => $b['label']]) }}"
                                        class="kc-btn kc-btn-ghost">
                                    <x-ui.icon name="x" /> {{ __('Quitar línea completa') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    {{-- Capa 1: documentos de conocimiento de la línea (plegados por defecto) --}}
                    <div class="kc-line-sub">
                        <button type="button" wire:click="toggleDocsOpen('{{ $b['key'] }}')" class="kc-area-toggle" aria-expanded="{{ $b['docs_open'] ? 'true' : 'false' }}">
                            <span @class(['kc-chev', 'open' => $b['docs_open']])><x-ui.icon name="chevron-down" /></span>
                            <span class="kc-line-sub-title">{{ __('Documentos de conocimiento') }}</span>
                            <span @class(['kc-badge', 't-green' => $b['docs_active'] > 0, 't-gray' => $b['docs_active'] === 0])>{{ __('Documentos :a/:t', ['a' => $b['docs_active'], 't' => $b['docs_total']]) }}</span>
                        </button>
                        @if ($b['docs_open'] && $b['docs'] !== [])
                            <button type="button" wire:click="toggleCategory('{{ $b['category_key'] }}')" wire:loading.attr="disabled"
                                class="kc-switch-btn" role="switch" aria-checked="{{ $b['docs_full'] ? 'true' : 'false' }}">
                                <span @class(['kc-switch', 'on' => $b['docs_full']])></span>
                                {{ __('Usar toda la categoría') }}
                            </button>
                        @endif
                    </div>
                    @if ($b['docs_open'])
                    @forelse ($b['docs'] as $r)
                        <div class="kc-src" wire:key="src-{{ $r['id'] }}">
                            <button type="button" wire:click="toggleSource({{ $r['id'] }})" class="kc-switch-btn"
                                role="switch" aria-checked="{{ $r['assigned'] === true ? 'true' : 'false' }}"
                                title="{{ $r['assigned'] === true ? __('Pausar para este agente') : __('Activar para este agente') }}"
                                aria-label="{{ $r['assigned'] === true ? __('Pausar para este agente') : __('Activar para este agente') }}">
                                <span @class(['kc-switch', 'on' => $r['assigned'] === true])></span>
                            </button>
                            <div class="kc-src-main">
                                <div class="kc-name">{{ $r['name'] }}</div>
                                <div class="kc-code" style="font-weight:500;margin-top:2px">{{ $r['code'] }}</div>
                            </div>
                            <div class="kc-src-tags">
                                @if ($r['assigned'] === true)
                                    <span class="kc-badge t-green"><span class="kc-dot"></span>{{ __('Activa para :bot', ['bot' => $bot->assistant_name]) }}</span>
                                @elseif ($r['assigned'] === false)
                                    <span class="kc-badge t-amber"><span class="kc-dot"></span>{{ __('Pausada') }}</span>
                                @else
                                    <span class="kc-badge t-gray"><span class="kc-dot"></span>{{ __('No asignada') }}</span>
                                @endif
                                @if (! $r['global_active'])
                                    <span class="kc-badge t-red" title="{{ __('Desactivada en la Biblioteca: ningún agente la usa') }}">{{ __('Inactiva global') }}</span>
                                @endif
                                @if ($r['shared_with'] !== [])
                                    <span class="kc-shared" title="{{ implode(', ', $r['shared_with']) }}">
                                        <span class="kc-avatars">
                                            @foreach (array_slice($r['shared_with'], 0, 3) as $agentName)
                                                @php $col = $kcAvatar($agentName); @endphp
                                                <span class="kc-av" style="background:{{ $col['bg'] }};color:{{ $col['fg'] }}">{{ Str::upper(Str::substr($agentName, 0, 1)) }}</span>
                                            @endforeach
                                        </span>
                                        {{ __('Compartida con: :names', ['names' => implode(', ', $r['shared_with'])]) }}
                                    </span>
                                @endif
                                @if ($r['assigned'] !== null)
                                    <button type="button" wire:click="detachSource({{ $r['id'] }})" class="kc-btn kc-btn-ghost kc-btn-sm" title="{{ __('Quitar solo de este agente') }}">
                                        <x-ui.icon name="x" /> {{ __('Quitar') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="kc-line-empty">{{ __('Sin documentos en esta línea.') }}</div>
                    @endforelse
                    @endif

                    {{-- Capa 2: programas que puede recomendar (área como subgrupo solo en Microcredenciales) --}}
                    <div class="kc-line-sub">
                        <span class="kc-line-sub-title">{{ __('Programas que puede recomendar') }}</span>
                    </div>
                    @foreach ($b['areas'] as $a)
                        @php $noArea = $a['key'] === 'sin_area'; @endphp
                        <div class="kc-area" wire:key="parea-{{ $b['key'] }}-{{ $a['key'] }}">
                            <div class="kc-area-head">
                                <button type="button" wire:click="toggleProgramAreaOpen('{{ $a['open_key'] }}')" class="kc-area-toggle" aria-expanded="{{ $a['open'] ? 'true' : 'false' }}">
                                    <span @class(['kc-chev', 'open' => $a['open']])><x-ui.icon name="chevron-down" /></span>
                                    <span class="kc-area-titles">
                                        <span class="kc-area-title">{{ $a['label'] }}</span>
                                        <span @class(['kc-badge', 't-green' => $a['assigned_count'] > 0, 't-gray' => $a['assigned_count'] === 0])>
                                            {{ trim($programSearch) !== ''
                                                ? __(':a de :t resultados asignados', ['a' => $a['assigned_count'], 't' => $a['total']])
                                                : __(':a de :t asignados', ['a' => $a['assigned_count'], 't' => $a['total']]) }}
                                        </span>
                                    </span>
                                </button>
                                {{-- Con búsqueda, las acciones masivas son las de «resultados». --}}
                                <div class="kc-area-actions">
                                    @if (trim($programSearch) === '' && $a['assigned_count'] < $a['total'])
                                        <button type="button" wire:click="assignProgramArea('{{ $a['key'] }}', '{{ $b['key'] }}')" wire:loading.attr="disabled" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="check" /> {{ __('Asignar toda el área') }}</button>
                                    @endif
                                    @if (trim($programSearch) === '' && $a['assigned_count'] > 0)
                                        <button type="button" wire:click="detachProgramArea('{{ $a['key'] }}', '{{ $b['key'] }}')" wire:loading.attr="disabled" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="x" /> {{ __('Quitar toda el área') }}</button>
                                    @endif
                                </div>
                            </div>

                            @if ($a['open'])
                                @foreach ($a['rows'] as $p)
                                    @include('ai::livewire.knowledge._program-row')
                                @endforeach
                            @endif
                        </div>
                    @endforeach

                    {{-- Resto de líneas: lista plana por nombre, sin subgrupos de área --}}
                    @if ($b['rows'] !== [])
                        <div class="kc-area kc-prog-flat">
                            @foreach ($b['rows'] as $p)
                                @include('ai::livewire.knowledge._program-row')
                            @endforeach
                        </div>
                    @endif

                    @if ($b['areas'] === [] && $b['rows'] === [])
                        <div class="kc-line-empty">
                            {{ trim($programSearch) !== '' && $b['programs_total'] > 0 ? __('Ningún programa de esta línea coincide con la búsqueda.') : __('Sin programas en esta línea.') }}
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
