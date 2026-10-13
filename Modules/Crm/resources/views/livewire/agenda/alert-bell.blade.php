<div wire:poll.60s>
    @if ($visible)
        <x-dropdown align="right" width="72">
            <x-slot name="trigger">
                <button type="button" class="icon-btn ui-bell" title="{{ __('Avisos del calendario') }}"
                    aria-label="{{ __('Avisos del calendario') }}{{ $unread > 0 ? ' · '.trans_choice(':n sin leer|:n sin leer', $unread, ['n' => $unread]) : '' }}">
                    <x-ui.icon name="calendar" />
                    @if ($unread > 0)
                        <span class="ui-bell-n" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </button>
            </x-slot>
            <x-slot name="content">
                <div class="ui-bell-h">
                    <strong>{{ __('Avisos del calendario') }}</strong>
                    @if ($unread > 0)
                        <x-ui.button size="sm" variant="ghost" wire:click="markAllRead">{{ __('Marcar leídos') }}</x-ui.button>
                    @endif
                </div>
                <ul class="ui-list ui-bell-list">
                    @forelse ($items as $n)
                        @php($d = $n->data)
                        <li>
                            <a href="{{ isset($d['activity_id']) ? route('crm.agenda.show', $d['activity_id']) : route('crm.agenda.index') }}" @class(['ui-list-item', 'is-unread' => $n->read_at === null])>
                                <span class="ui-list-main">
                                    <span class="ui-bell-kind">{{ ['reminder' => __('Recordatorio'), 'booking' => __('Nueva reserva'), 'booking_rescheduled' => __('Reserva reprogramada'), 'booking_cancelled' => __('Reserva cancelada')][$d['kind'] ?? ''] ?? __('Actividad asignada') }}</span>
                                    <span class="ui-list-title">{{ $d['title'] ?? '' }}</span>
                                    @if (! empty($d['starts_at']))
                                        <span class="ui-list-meta">{{ \Illuminate\Support\Carbon::parse($d['starts_at'])->setTimezone($d['timezone'] ?? 'UTC')->format('d/m H:i') }} ({{ $d['timezone'] ?? 'UTC' }})</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="ui-list-empty">{{ __('Sin avisos del calendario.') }}</li>
                    @endforelse
                </ul>
            </x-slot>
        </x-dropdown>
    @endif
</div>
