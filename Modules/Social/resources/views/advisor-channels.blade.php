<section class="ui-card" data-testid="advisor-channels" aria-labelledby="ach-title">
    <div class="ui-card-h"><x-ui.icon name="inbox" class="ui-card-ic" /><h2 id="ach-title">{{ __('Canales de atención automática') }}</h2></div>
    <div class="ui-card-p">
        <p class="ui-card-desc">{{ __(':advisor responde con el mismo motor, instrucciones y conocimiento en todos sus canales. Cada cuenta conectada la atiende un solo asesor. Activar una cuenta solo afecta a los mensajes nuevos; apagarla no borra conversaciones ni mensajes.', ['advisor' => $bot->assistant_name]) }}</p>

        @if ($notice)
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status" data-testid="advisor-channels-notice"><x-ui.icon name="check" /> {{ $notice }}</div>
        @endif
        @if ($error)
            <div class="ui-alert ui-alert--flush ui-tone--danger" role="alert" data-testid="advisor-channels-error"><x-ui.icon name="x" /> {{ $error }}</div>
        @endif
        @if (! $ready)
            <div class="ui-callout ui-callout--sm ui-tone--warning @if ($notice || $error) ui-actions-row--top @endif" data-testid="advisor-channels-not-ready"><x-ui.icon name="alert-triangle" /> <span>{{ $pendingReason }}</span></div>
        @endif
    </div>

    {{-- Estado del procesamiento automático, con sus planos separados: planificador (quien garantiza
         el despacho), worker (última ejecución real; arranca solo con trabajos), interruptor general
         de la instalación y trabajos de la cola. --}}
    <p class="ui-card-kicker">{{ __('Estado del servicio') }}</p>
    <ul class="ui-list" data-testid="advisor-service-status">
        <li>
            <div class="ui-list-item ui-list-item--wrap" data-testid="status-scheduler">
                <span class="ui-list-main">
                    <span class="ui-list-title">{{ __('Planificador del servidor') }}</span>
                    <span class="ui-list-meta">{{ $status['scheduler']['last_seen'] ? __('Última pasada :when', ['when' => $status['scheduler']['last_seen']->diffForHumans()]) : __('Todavía sin pasadas registradas') }}</span>
                </span>
                <span class="ui-list-aside">
                    <x-ui.badge :tone="$status['scheduler']['running'] ? 'success' : 'danger'" dot>{{ $status['scheduler']['running'] ? __('En marcha') : __('Detenido') }}</x-ui.badge>
                </span>
            </div>
        </li>
        <li>
            <div class="ui-list-item ui-list-item--wrap" data-testid="status-worker">
                <span class="ui-list-main">
                    <span class="ui-list-title">{{ __('Worker del asesor') }}</span>
                    <span class="ui-list-meta">
                        {{ $status['worker']['listening'] ? __('Escucha continua cada minuto: hay cuentas con el asesor activo.') : __('Arranca solo cuando hay trabajos en la cola.') }}
                        @if ($status['worker']['last_seen'])
                            {{ __('Última ejecución :when', ['when' => $status['worker']['last_seen']->diffForHumans()]) }}
                        @endif
                    </span>
                </span>
                <span class="ui-list-aside">
                    @if ($status['worker']['stalled'])
                        <x-ui.badge tone="warning" dot data-testid="status-worker-stalled">{{ __('Trabajos listos sin atender') }}</x-ui.badge>
                    @else
                        <x-ui.badge :tone="$status['worker']['recent'] ? 'success' : 'neutral'" dot>{{ $status['worker']['recent'] ? __('Reciente') : __('En espera') }}</x-ui.badge>
                    @endif
                </span>
            </div>
        </li>
        <li>
            <div class="ui-list-item ui-list-item--wrap" data-testid="status-autoreply">
                <span class="ui-list-main">
                    <span class="ui-list-title">{{ __('Asesor automático de la instalación') }}</span>
                    <span class="ui-list-meta">{{ __('Interruptor general del servidor; cada cuenta se activa además aquí abajo.') }}</span>
                </span>
                <span class="ui-list-aside">
                    <x-ui.badge :tone="$status['autoreply_enabled'] ? 'success' : 'neutral'" dot>{{ $status['autoreply_enabled'] ? __('Habilitado') : __('Deshabilitado') }}</x-ui.badge>
                </span>
            </div>
        </li>
        <li>
            <div class="ui-list-item ui-list-item--wrap" data-testid="status-jobs">
                <span class="ui-list-main">
                    <span class="ui-list-title">{{ __('Trabajos en la cola del asesor') }}</span>
                    <span class="ui-list-meta">{{ __(':ready listos · :delayed retrasados · :reserved en ejecución · :failed fallidos', $status['jobs']) }}</span>
                </span>
                <span class="ui-list-aside">
                    <x-ui.badge :tone="$status['jobs']['failed'] > 0 ? 'warning' : 'neutral'">{{ $status['jobs']['total'] === 0 ? __('Ninguno') : trans_choice(':n en cola|:n en cola', $status['jobs']['total'], ['n' => $status['jobs']['total']]) }}</x-ui.badge>
                </span>
            </div>
        </li>
    </ul>

    {{-- Web Chat: sin cambios. Lo controla el estado del asesor y el widget incrustado. --}}
    <p class="ui-card-kicker">{{ __('Web Chat') }}</p>
    <ul class="ui-list">
        <li>
            <div class="ui-list-item ui-list-item--wrap" data-testid="channel-web">
                <span class="ui-list-main">
                    <span class="ui-list-title">{{ __('Widget de la web') }}</span>
                    <span class="ui-list-meta">{{ __('Responde en las páginas donde esté incrustado su widget mientras el asesor esté activo (ver «Incrustar widget»).') }}</span>
                </span>
                <span class="ui-list-aside">
                    <x-ui.badge :tone="$bot->status === 'active' ? 'success' : 'neutral'" dot>{{ $bot->status === 'active' ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                </span>
            </div>
        </li>
    </ul>

    @foreach ($groups as $provider => $group)
        <p class="ui-card-kicker">{{ __($group['label']) }}</p>
        <ul class="ui-list">
            @forelse ($group['channels'] as $ch)
                @php
                    $mine = (int) $ch->advisor_bot_id === (int) $bot->getKey();
                    $on = $mine && $ch->advisor_enabled;
                    $other = ! $mine && $ch->advisor_bot_id !== null && $ch->advisor_enabled;
                    $conn = $ch->connection_status ?? 'connected_cloud_api';
                    $usable = $ch->automationCanSend();
                @endphp
                <li wire:key="ach-{{ $ch->id }}">
                    <div class="ui-list-item ui-list-item--wrap" data-testid="channel-{{ $ch->id }}">
                        <span class="ui-list-main">
                            <span class="ui-list-title" id="ach-name-{{ $ch->id }}">{{ $ch->display_name }}</span>
                            <span class="ui-person-line">
                                <x-ui.badge :tone="$usable ? 'success' : 'danger'" dot>{{ $ch->is_active ? __($ch->connectionLabel()) : __('Canal inactivo') }}</x-ui.badge>
                                @if ($other)
                                    <x-ui.badge tone="warning" data-testid="conflict-{{ $ch->id }}">{{ __('Atendida por :advisor', ['advisor' => $ch->advisorBot?->assistant_name ?? __('otro asesor')]) }}</x-ui.badge>
                                @endif
                            </span>
                            @if ($ch->advisor_assigned_at)
                                <span class="ui-list-meta">{{ __('Último cambio: :who · :when', ['who' => $ch->advisorAssigner?->name ?? __('Sistema'), 'when' => $ch->advisor_assigned_at->diffForHumans()]) }}</span>
                            @endif
                        </span>
                        <span class="ui-list-aside">
                            @if ($other)
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" wire:click="reassign({{ $ch->id }}, {{ (int) $ch->advisor_bot_id }})"
                                        wire:confirm="{{ __('¿Reasignar :channel a :new? :old dejará de responder en esta cuenta en ese mismo instante.', ['channel' => $ch->display_name, 'new' => $bot->assistant_name, 'old' => $ch->advisorBot?->assistant_name ?? __('El otro asesor')]) }}">{{ __('Reasignar a este asesor') }}</button>
                            @else
                                <button type="button" class="ui-switch" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" data-testid="toggle-{{ $ch->id }}"
                                        aria-labelledby="ach-name-{{ $ch->id }} ach-state-{{ $ch->id }}"
                                        wire:click="toggle({{ $ch->id }})" @disabled(! $on && ! $ready)>
                                    <span class="ui-switch-track" aria-hidden="true"></span> <span id="ach-state-{{ $ch->id }}">{{ $on ? __('Activado') : __('Desactivado') }}</span>
                                </button>
                            @endif
                        </span>
                    </div>
                </li>
            @empty
                <li class="ui-list-empty">{{ __('No hay cuentas de :provider conectadas.', ['provider' => __($group['label'])]) }}</li>
            @endforelse
        </ul>
    @endforeach
</section>
