<div class="ag-lead" style="font-size:12.5px">
    @if ($visible)
        @if ($next)
            @php $s = $next->starts_at->copy()->setTimezone($tz); @endphp
            <a href="{{ route('crm.agenda.show', $next) }}" style="display:block;text-decoration:none;color:inherit;background:#F3F8FE;border:1px solid #D6E5F7;border-left:3px solid #1E5AA8;border-radius:10px;padding:9px 11px;margin-bottom:8px">
                <div style="font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#1E5AA8">{{ __('Próxima actividad') }}</div>
                <div style="font-weight:700;margin-top:2px">{{ $next->title }}</div>
                <div style="color:#61748F">{{ $s->translatedFormat('D j M') }} · {{ $s->format('H:i') }} · {{ $next->medium->label() }} · {{ $next->assignee?->name }}</div>
            </a>
        @else
            <p style="color:#61748F;margin:0 0 8px">{{ __('Sin actividades programadas.') }}</p>
        @endif

        @foreach ($upcoming as $a)
            @php $s = $a->starts_at->copy()->setTimezone($tz); @endphp
            <a href="{{ route('crm.agenda.show', $a) }}" class="ev" style="text-decoration:none;color:inherit" wire:key="la-up-{{ $a->id }}">
                <x-ui.icon name="calendar" class="i14" />
                <div>{{ $a->title }}<div class="t">{{ $s->translatedFormat('D j M') }} · {{ $s->format('H:i') }} · {{ $a->type->label() }}</div></div>
            </a>
        @endforeach

        @if ($recent->isNotEmpty())
            <div style="font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#61748F;margin:10px 0 2px">{{ __('Completadas recientes') }}</div>
            @foreach ($recent as $a)
                <a href="{{ route('crm.agenda.show', $a) }}" class="ev" style="text-decoration:none;color:inherit" wire:key="la-rc-{{ $a->id }}">
                    <x-ui.icon name="check" class="i14" />
                    <div>{{ $a->title }}<div class="t">{{ $a->completed_at?->setTimezone($tz)->translatedFormat('j M Y') }}@if ($a->outcome) · {{ \Illuminate\Support\Str::limit($a->outcome, 60) }}@endif</div></div>
                </a>
            @endforeach
        @endif

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
            @if ($canCreate)
                <a href="{{ route('crm.agenda.create', ['lead' => $lead->id]) }}" class="ghost solid" style="padding:6px 10px;font-size:12.5px"><x-ui.icon name="plus" class="i14" /> {{ __('Programar actividad') }}</a>
            @endif
            <a href="{{ route('crm.agenda.index') }}" class="ghost" style="padding:6px 10px;font-size:12.5px"><x-ui.icon name="calendar" class="i14" /> {{ __('Abrir Calendario') }}</a>
            @if ($canCreate)
                <button type="button" class="ghost" style="padding:6px 10px;font-size:12.5px" wire:click="generateBookingLink"><x-ui.icon name="external-link" class="i14" /> {{ __('Enlace de reserva') }}</button>
            @endif
        </div>
        @if ($bookingUrl !== '')
            <div x-data="{ copied: false }" style="margin-top:8px;background:#F1F6FC;border:1px solid #D9E6F6;border-radius:10px;padding:8px 10px">
                <div style="overflow-wrap:anywhere;font-size:12px">{{ $bookingUrl }}</div>
                <button type="button" style="margin-top:6px;border:none;background:none;color:#1E5AA8;font-weight:600;cursor:pointer;padding:0;font-size:12px"
                        x-on:click="navigator.clipboard.writeText(@js($bookingUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-show="!copied">{{ __('Copiar enlace') }}</span><span x-show="copied">{{ __('Copiado') }}</span>
                </button>
            </div>
        @endif
        @if ($bookingNotice !== '')
            <p style="color:#61748F;font-size:11.5px;margin:6px 0 0">{{ $bookingNotice }}</p>
        @endif
    @endif
</div>
