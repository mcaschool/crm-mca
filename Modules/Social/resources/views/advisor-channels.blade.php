<div class="card card-p fade" style="margin-top:22px" data-testid="advisor-channels">
    <style>
        .ach-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-top:8px;font-size:13px}
        .ach-row__name{font-weight:600;flex:1 1 200px;min-width:0}
        .ach-row__meta{font-size:12px;color:var(--muted);flex-basis:100%}
        .ach-pill{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;background:#EEF1F5;color:#5A6B84;white-space:nowrap}
        .ach-pill--ok{background:#E5F4EE;color:#1F7A55}
        .ach-pill--warn{background:#FBF0DC;color:#8A5A0C}
        .ach-pill--err{background:#FBE7E5;color:#8A1C1C}
        .ach-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;background:none;border:0;padding:0;font:inherit;color:inherit}
        .ach-switch__track{width:36px;height:20px;border-radius:999px;background:#CBD3DF;position:relative;transition:background .15s}
        .ach-switch__track::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s}
        .ach-switch[aria-checked="true"] .ach-switch__track{background:var(--mca,#1E5AA8)}
        .ach-switch[aria-checked="true"] .ach-switch__track::after{left:18px}
        .ach-switch:disabled{opacity:.5;cursor:not-allowed}
        .ach-group{margin-top:14px;font-size:12.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
    </style>

    <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
        <h3 style="display:flex;align-items:center;gap:8px"><x-ui.icon name="message-circle" class="ic" style="width:17px;height:17px" /> {{ __('Canales de atención automática') }}</h3>
        <p class="mca-sub">{{ __(':advisor responde con el mismo motor, instrucciones y conocimiento en todos sus canales. Cada cuenta conectada la atiende un solo asesor. Activar una cuenta solo afecta a los mensajes nuevos; apagarla no borra conversaciones ni mensajes.', ['advisor' => $bot->assistant_name]) }}</p>
    </div>

    @if ($notice)
        <div class="mca-toast ok fade" data-testid="advisor-channels-notice"><x-ui.icon name="check" class="ic" /> {{ $notice }}</div>
    @endif
    @if ($error)
        <div class="mca-toast err fade" data-testid="advisor-channels-error"><x-ui.icon name="x" class="ic" /> {{ $error }}</div>
    @endif

    {{-- Web Chat: sin cambios. Lo controla el estado del asesor y el widget incrustado. --}}
    <div class="ach-group">{{ __('Web Chat') }}</div>
    <div class="ach-row" data-testid="channel-web">
        <span class="ach-row__name">{{ __('Widget de la web') }}</span>
        <span class="ach-pill {{ $bot->status === 'active' ? 'ach-pill--ok' : '' }}">{{ $bot->status === 'active' ? __('Activo') : __('Inactivo') }}</span>
        <span class="ach-row__meta">{{ __('Responde en las páginas donde esté incrustado su widget mientras el asesor esté activo (ver «Incrustar widget»).') }}</span>
    </div>

    @if (! $ready)
        <div class="mca-help" style="margin-top:12px" data-testid="advisor-channels-not-ready">{{ $pendingReason }}</div>
    @endif

    @foreach ($groups as $provider => $group)
        <div class="ach-group">{{ __($group['label']) }}</div>
        @forelse ($group['channels'] as $ch)
            @php
                $mine = (int) $ch->advisor_bot_id === (int) $bot->getKey();
                $on = $mine && $ch->advisor_enabled;
                $other = ! $mine && $ch->advisor_bot_id !== null && $ch->advisor_enabled;
                $conn = $ch->connection_status ?? 'connected_cloud_api';
                $usable = $ch->automationCanSend();
            @endphp
            <div class="ach-row" wire:key="ach-{{ $ch->id }}" data-testid="channel-{{ $ch->id }}">
                <span class="ach-row__name">{{ $ch->display_name }}</span>
                <span class="ach-pill {{ $usable ? 'ach-pill--ok' : 'ach-pill--err' }}">{{ $ch->is_active ? __($ch->connectionLabel()) : __('Canal inactivo') }}</span>
                @if ($other)
                    <span class="ach-pill ach-pill--warn" data-testid="conflict-{{ $ch->id }}">{{ __('Atendida por :advisor', ['advisor' => $ch->advisorBot?->assistant_name ?? __('otro asesor')]) }}</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="reassign({{ $ch->id }}, {{ (int) $ch->advisor_bot_id }})"
                            wire:confirm="{{ __('¿Reasignar :channel a :new? :old dejará de responder en esta cuenta en ese mismo instante.', ['channel' => $ch->display_name, 'new' => $bot->assistant_name, 'old' => $ch->advisorBot?->assistant_name ?? __('El otro asesor')]) }}">{{ __('Reasignar a este asesor') }}</button>
                @else
                    <button type="button" class="ach-switch" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" data-testid="toggle-{{ $ch->id }}"
                            wire:click="toggle({{ $ch->id }})" @disabled(! $on && ! $ready)>
                        <span class="ach-switch__track"></span> {{ $on ? __('Activado') : __('Desactivado') }}
                    </button>
                @endif
                @if ($ch->advisor_assigned_at)
                    <span class="ach-row__meta">{{ __('Último cambio: :who · :when', ['who' => $ch->advisorAssigner?->name ?? __('Sistema'), 'when' => $ch->advisor_assigned_at->diffForHumans()]) }}</span>
                @endif
            </div>
        @empty
            <div class="mca-help" style="margin-top:6px">{{ __('No hay cuentas de :provider conectadas.', ['provider' => __($group['label'])]) }}</div>
        @endforelse
    @endforeach
</div>
