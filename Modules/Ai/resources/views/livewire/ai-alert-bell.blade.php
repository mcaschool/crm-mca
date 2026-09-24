<div wire:poll.30s>
    @if ($visible)
        <x-dropdown align="right" width="72">
            <x-slot name="trigger">
                <button type="button" class="icon-btn" style="position:relative;cursor:pointer" title="{{ __('Alertas de IA') }}">
                    <x-ui.icon name="bell" style="width:18px;height:18px" />
                    @if ($unread > 0)
                        <span style="position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:9px;background:#C0392B;color:#fff;font-size:10px;line-height:16px;text-align:center;font-weight:700">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </button>
            </x-slot>
            <x-slot name="content">
                <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px">
                    <strong style="font-size:13px">{{ __('Alertas de IA') }}</strong>
                    @if ($unread > 0)
                        <button type="button" wire:click="markAllRead" class="btn btn-soft btn-sm">{{ __('Marcar leídas') }}</button>
                    @endif
                </div>
                @forelse ($items as $n)
                    @php($d = $n->data)
                    <div style="padding:10px 14px;border-bottom:1px solid var(--line);{{ $n->read_at === null ? 'background:#FCF3F2' : '' }}">
                        <div style="font-size:12.5px;font-weight:700;color:{{ ($d['type'] ?? '') === 'ai_service_restored' ? '#1E7A46' : '#8A1C1C' }}">{{ $d['title'] ?? __('Alerta de IA') }}</div>
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px">
                            {{ $d['process'] ?? '' }} · {{ $d['provider'] ?? '' }}/{{ $d['model'] ?? '' }}
                            @if (! empty($d['category_label'])) · {{ $d['category_label'] }} @endif
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ $n->created_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <div style="padding:14px;font-size:12.5px;color:var(--muted)">{{ __('Sin alertas de IA.') }}</div>
                @endforelse
            </x-slot>
        </x-dropdown>
    @endif
</div>
