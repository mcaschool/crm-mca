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
                        <span class="kc-group-title">{{ $noCat ? __('Sin categoría') : Str::title($g['label']) }}</span>
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
    </div>
</div>
