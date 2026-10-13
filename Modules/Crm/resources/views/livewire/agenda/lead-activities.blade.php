<div class="ui-lead-agenda">
    @if ($visible)
        @if ($next)
            @php $s = $next->starts_at->copy()->setTimezone($tz); @endphp
            <a href="{{ route('crm.agenda.show', $next) }}" class="ui-next-activity">
                <span class="ui-next-kicker">{{ __('Próxima actividad') }}</span>
                <span class="ui-list-title">{{ $next->title }}</span>
                <span class="ui-list-meta">{{ $s->translatedFormat('D j M') }} · {{ $s->format('H:i') }} · {{ $next->medium->label() }} · {{ $next->assignee?->name }}</span>
            </a>
        @else
            <p class="ui-help">{{ __('Sin actividades programadas.') }}</p>
        @endif

        @if ($upcoming->isNotEmpty())
            <ul class="ui-list ui-list--compact">
                @foreach ($upcoming as $a)
                    @php $s = $a->starts_at->copy()->setTimezone($tz); @endphp
                    <li wire:key="la-up-{{ $a->id }}">
                        <a href="{{ route('crm.agenda.show', $a) }}" class="ui-list-item">
                            <span class="ui-tl-ic ui-tone--blue"><x-ui.icon name="calendar" /></span>
                            <span class="ui-list-main"><span class="ui-list-title">{{ $a->title }}</span><span class="ui-list-meta">{{ $s->translatedFormat('D j M') }} · {{ $s->format('H:i') }} · {{ $a->type->label() }}</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($recent->isNotEmpty())
            <p class="ui-next-kicker ui-next-kicker--muted">{{ __('Completadas recientes') }}</p>
            <ul class="ui-list ui-list--compact">
                @foreach ($recent as $a)
                    <li wire:key="la-rc-{{ $a->id }}">
                        <a href="{{ route('crm.agenda.show', $a) }}" class="ui-list-item">
                            <span class="ui-tl-ic ui-tone--success"><x-ui.icon name="check" /></span>
                            <span class="ui-list-main"><span class="ui-list-title">{{ $a->title }}</span><span class="ui-list-meta">{{ $a->completed_at?->setTimezone($tz)->translatedFormat('j M Y') }}@if ($a->outcome) · {{ \Illuminate\Support\Str::limit($a->outcome, 60) }}@endif</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="ui-lead-agenda-actions">
            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" :href="route('crm.agenda.create', ['lead' => $lead->id])">{{ __('Programar actividad') }}</x-ui.button>
            @endif
            <x-ui.button size="sm" variant="ghost" icon="calendar" :href="route('crm.agenda.index')">{{ __('Abrir Calendario') }}</x-ui.button>
            @if ($canCreate)
                <x-ui.button size="sm" variant="ghost" icon="external-link" wire:click="generateBookingLink">{{ __('Enlace de reserva') }}</x-ui.button>
            @endif
        </div>
        @if ($bookingUrl !== '')
            <div x-data="{ copied: false }" class="ui-callout ui-callout--sm ui-tone--blue ui-callout--stack">
                <span class="ui-mono ui-break">{{ $bookingUrl }}</span>
                <button type="button" class="ui-link ui-link--btn"
                        x-on:click="navigator.clipboard.writeText(@js($bookingUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-show="!copied">{{ __('Copiar enlace') }}</span><span x-show="copied">{{ __('Copiado') }}</span>
                </button>
            </div>
        @endif
        @if ($bookingNotice !== '')
            <p class="ui-help">{{ $bookingNotice }}</p>
        @endif
    @endif
</div>
