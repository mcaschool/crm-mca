{{-- Fila de un programa en «Por agente» (con interruptor de asignación). Recibe $p y $bot. --}}
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
