{{-- Fila de un programa en «Por agente» (con interruptor de asignación). Recibe $p y $bot. --}}
<li @class(['ui-list-item', 'ui-list-item--coded', 'is-off' => ! $p['active']]) wire:key="prog-{{ $p['id'] }}">
    <button type="button" wire:click="toggleProgram({{ $p['id'] }})" class="ui-switch"
        role="switch" aria-checked="{{ $p['assigned'] ? 'true' : 'false' }}"
        title="{{ $p['assigned'] ? __('Quitar de :bot', ['bot' => $bot->assistant_name]) : __('Asignar a :bot', ['bot' => $bot->assistant_name]) }}"
        aria-label="{{ $p['assigned'] ? __('Quitar de :bot', ['bot' => $bot->assistant_name]) : __('Asignar a :bot', ['bot' => $bot->assistant_name]) }}">
        <span class="ui-switch-track" aria-hidden="true"></span>
    </button>
    <span class="ui-mono">{{ $p['code'] !== '' ? $p['code'] : '—' }}</span>
    <div class="ui-list-main"><span class="ui-list-title">{{ $p['name'] }}</span></div>
    <div class="ui-list-aside">
        @if ($p['active'])
            <x-ui.badge tone="success" dot>{{ __('Activo') }}</x-ui.badge>
        @else
            <x-ui.badge tone="neutral" dot title="{{ __('Inactivo en el catálogo: el emparejador no lo recomienda aunque esté asignado.') }}">{{ __('Inactivo') }}</x-ui.badge>
        @endif
    </div>
</li>
