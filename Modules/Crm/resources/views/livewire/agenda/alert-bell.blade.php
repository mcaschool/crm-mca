<div wire:poll.60s>
    @if ($visible)
        <x-dropdown align="right" width="72">
            <x-slot name="trigger">
                <button type="button" class="icon-btn" style="position:relative;cursor:pointer" title="{{ __('Avisos del calendario') }}">
                    <x-ui.icon name="calendar" style="width:18px;height:18px" />
                    @if ($unread > 0)
                        <span style="position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:9px;background:#1E5AA8;color:#fff;font-size:10px;line-height:16px;text-align:center;font-weight:700">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </button>
            </x-slot>
            <x-slot name="content">
                <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px">
                    <strong style="font-size:13px">{{ __('Avisos del calendario') }}</strong>
                    @if ($unread > 0)
                        <button type="button" wire:click="markAllRead" class="btn btn-soft btn-sm">{{ __('Marcar leídos') }}</button>
                    @endif
                </div>
                @forelse ($items as $n)
                    @php($d = $n->data)
                    <a href="{{ isset($d['activity_id']) ? route('crm.agenda.show', $d['activity_id']) : route('crm.agenda.index') }}"
                       style="display:block;text-decoration:none;color:inherit;padding:10px 14px;border-bottom:1px solid var(--line);{{ $n->read_at === null ? 'background:#F1F6FC' : '' }}">
                        <div style="font-size:11px;font-weight:700;color:#1E5AA8;text-transform:uppercase;letter-spacing:.05em">{{ ($d['kind'] ?? '') === 'reminder' ? __('Recordatorio') : __('Actividad asignada') }}</div>
                        <div style="font-size:12.5px;font-weight:600;margin-top:2px">{{ $d['title'] ?? '' }}</div>
                        @if (! empty($d['starts_at']))
                            <div style="font-size:11.5px;color:var(--muted);margin-top:2px">{{ \Illuminate\Support\Carbon::parse($d['starts_at'])->setTimezone($d['timezone'] ?? 'UTC')->format('d/m H:i') }} ({{ $d['timezone'] ?? 'UTC' }})</div>
                        @endif
                    </a>
                @empty
                    <div style="padding:14px;font-size:12.5px;color:var(--muted)">{{ __('Sin avisos del calendario.') }}</div>
                @endforelse
            </x-slot>
        </x-dropdown>
    @endif
</div>
