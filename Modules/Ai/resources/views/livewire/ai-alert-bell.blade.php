<div wire:poll.30s>
    @if ($visible)
        <x-dropdown align="right" width="72">
            <x-slot name="trigger">
                <button type="button" class="icon-btn ui-bell" title="{{ __('Alertas de IA') }}"
                    aria-label="{{ __('Alertas de IA') }}{{ $unread > 0 ? ' · '.trans_choice(':n sin leer|:n sin leer', $unread, ['n' => $unread]) : '' }}">
                    <x-ui.icon name="bell" />
                    @if ($unread > 0)
                        <span class="ui-bell-n ui-bell-n--danger" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </button>
            </x-slot>
            <x-slot name="content">
                <div class="ui-bell-panel">
                <div class="ui-bell-h">
                    <strong>{{ __('Alertas de IA') }}</strong>
                    @if ($unread > 0)
                        <x-ui.button size="sm" variant="ghost" wire:click="markAllRead">{{ __('Marcar leídas') }}</x-ui.button>
                    @endif
                </div>
                <ul class="ui-list ui-bell-list">
                    @forelse ($items as $n)
                        @php($d = $n->data)
                        <li>
                            <div @class(['ui-list-item', 'is-unread' => $n->read_at === null])>
                                <span class="ui-list-main">
                                    <span @class(['ui-list-title ui-bell-tone', 'ui-tone--success' => ($d['type'] ?? '') === 'ai_service_restored', 'ui-tone--danger' => ($d['type'] ?? '') !== 'ai_service_restored'])>{{ $d['title'] ?? __('Alerta de IA') }}</span>
                                    <span class="ui-list-meta">
                                        {{ $d['process'] ?? '' }} · {{ $d['provider'] ?? '' }}/{{ $d['model'] ?? '' }}
                                        @if (! empty($d['category_label'])) · {{ $d['category_label'] }} @endif
                                    </span>
                                    <span class="ui-list-meta">{{ $n->created_at?->diffForHumans() }}</span>
                                </span>
                            </div>
                        </li>
                    @empty
                        <li class="ui-list-empty">{{ __('Sin alertas de IA.') }}</li>
                    @endforelse
                </ul>
                </div>
            </x-slot>
        </x-dropdown>
    @endif
</div>
