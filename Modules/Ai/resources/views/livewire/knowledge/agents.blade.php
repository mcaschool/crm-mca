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
    $kcTotalActive = $groups->sum('active_count');
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

        @if ($bot !== null)
            <div class="kc-section-head">
                <div>
                    <h2 class="kc-section-title">{{ __('Conocimiento') }}</h2>
                    <p class="kc-section-sub">{{ __('Fuentes de la biblioteca que :bot usa para responder.', ['bot' => $bot->assistant_name]) }}</p>
                </div>
            </div>
        @endif

        @if ($bot === null)
            <div class="kc-card kc-empty">
                <span class="kc-ic t-blue"><x-ui.icon name="bot" /></span>
                <p>{{ __('No hay agentes activos. Crea o activa un asesor en «Asesores Inteligentes».') }}</p>
            </div>
        @elseif ($groups->isEmpty())
            <div class="kc-card kc-empty">
                <span class="kc-ic t-blue"><x-ui.icon name="book-open" /></span>
                <p>{{ __('La biblioteca está vacía. Sube fuentes .md en la pestaña Biblioteca.') }}</p>
            </div>
        @else
            @foreach ($groups as $g)
                @php $noCat = $g['key'] === 'sin_categoria'; @endphp
                <div class="kc-card kc-group" wire:key="grp-{{ $g['key'] }}">
                    <div class="kc-group-head">
                        <span @class(['kc-ic', 't-amber' => $noCat, 't-blue' => ! $noCat])><x-ui.icon name="{{ $noCat ? 'alert-triangle' : 'layers' }}" /></span>
                        <span class="kc-group-title">{{ $noCat ? __('Sin categoría') : (\Modules\Ai\Support\KnowledgeTaxonomy::isLine($g['key']) ? __((string) \Modules\Ai\Support\KnowledgeTaxonomy::lineLabel($g['key'])) : Str::title($g['label'])) }}</span>
                        <span @class(['kc-badge', 't-green' => $g['active_count'] > 0, 't-gray' => $g['active_count'] === 0])>
                            {{ __(':a de :t activas para :bot', ['a' => $g['active_count'], 't' => count($g['rows']), 'bot' => $bot->assistant_name]) }}
                        </span>
                        <button type="button" wire:click="toggleCategory('{{ $g['key'] }}')" wire:loading.attr="disabled"
                            class="kc-switch-btn" role="switch" aria-checked="{{ $g['full'] ? 'true' : 'false' }}">
                            <span @class(['kc-switch', 'on' => $g['full']])></span>
                            {{ __('Usar toda la categoría') }}
                        </button>
                    </div>

                    @foreach ($g['rows'] as $r)
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
                    @endforeach
                </div>
            @endforeach
        @endif

        {{-- Programas que puede recomendar (Bloque 4c) --}}
        @if ($bot !== null)
            <div class="kc-section-head kc-section-gap">
                <div>
                    <h2 class="kc-section-title">{{ __('Programas que puede recomendar') }}</h2>
                    <p class="kc-section-sub">{{ __('El emparejador de :bot solo recomienda los programas asignados y activos.', ['bot' => $bot->assistant_name]) }}</p>
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

            @forelse ($programAreas as $a)
                @php $noArea = $a['key'] === 'sin_area'; @endphp
                <div class="kc-card kc-group" wire:key="parea-{{ $a['key'] }}">
                    <div class="kc-group-head">
                        <button type="button" wire:click="toggleProgramAreaOpen('{{ $a['key'] }}')" class="kc-area-toggle" aria-expanded="{{ $a['open'] ? 'true' : 'false' }}">
                            <span @class(['kc-chev', 'open' => $a['open']])><x-ui.icon name="chevron-down" /></span>
                            <span @class(['kc-ic', 't-amber' => $noArea, 't-blue' => ! $noArea])><x-ui.icon name="{{ $noArea ? 'alert-triangle' : 'book-open' }}" /></span>
                            <span class="kc-area-titles">
                                <span class="kc-group-title">{{ $a['label'] }}</span>
                                <span @class(['kc-badge', 't-green' => $a['assigned_count'] > 0, 't-gray' => $a['assigned_count'] === 0])>
                                    {{ trim($programSearch) !== ''
                                        ? __(':a de :t resultados asignados', ['a' => $a['assigned_count'], 't' => $a['total']])
                                        : __(':a de :t asignados', ['a' => $a['assigned_count'], 't' => $a['total']]) }}
                                </span>
                            </span>
                        </button>
                        {{-- Con búsqueda, las acciones masivas son las de «resultados» (el área entera
                             incluiría programas que no se están viendo). --}}
                        <div class="kc-area-actions">
                            @if (trim($programSearch) === '' && $a['assigned_count'] < $a['total'])
                                <button type="button" wire:click="assignProgramArea('{{ $a['key'] }}')" wire:loading.attr="disabled" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="check" /> {{ __('Asignar toda el área') }}</button>
                            @endif
                            @if (trim($programSearch) === '' && $a['assigned_count'] > 0)
                                <button type="button" wire:click="detachProgramArea('{{ $a['key'] }}')" wire:loading.attr="disabled" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="x" /> {{ __('Quitar toda el área') }}</button>
                            @endif
                        </div>
                    </div>

                    @if ($a['open'])
                        @foreach ($a['rows'] as $p)
                            <div @class(['kc-src', 'kc-prog-row', 'is-off' => ! $p['active']]) wire:key="prog-{{ $p['id'] }}">
                                <button type="button" wire:click="toggleProgram({{ $p['id'] }})" class="kc-switch-btn"
                                    role="switch" aria-checked="{{ $p['assigned'] ? 'true' : 'false' }}"
                                    title="{{ $p['assigned'] ? __('Quitar de :bot', ['bot' => $bot->assistant_name]) : __('Asignar a :bot', ['bot' => $bot->assistant_name]) }}"
                                    aria-label="{{ $p['assigned'] ? __('Quitar de :bot', ['bot' => $bot->assistant_name]) : __('Asignar a :bot', ['bot' => $bot->assistant_name]) }}">
                                    <span @class(['kc-switch', 'on' => $p['assigned']])></span>
                                </button>
                                <span class="kc-code kc-prog-code">{{ $p['code'] !== '' ? $p['code'] : '—' }}</span>
                                <div class="kc-src-main"><div class="kc-name">{{ $p['name'] }}</div></div>
                                <div class="kc-src-tags">
                                    @if ($p['active'])
                                        <span class="kc-badge t-green"><span class="kc-dot"></span>{{ __('Activo') }}</span>
                                    @else
                                        <span class="kc-badge t-gray" title="{{ __('Inactivo en el catálogo: el emparejador no lo recomienda aunque esté asignado.') }}"><span class="kc-dot"></span>{{ __('Inactivo') }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            @empty
                <div class="kc-card kc-empty">
                    <span class="kc-ic t-blue"><x-ui.icon name="search" /></span>
                    <p>{{ trim($programSearch) !== '' ? __('Ningún programa coincide con la búsqueda.') : __('El catálogo no tiene programas.') }}</p>
                </div>
            @endforelse
        @endif
    </div>
</div>
