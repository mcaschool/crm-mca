{{-- Actividad del Calendario: hora, tipo (icono), título, lead/contacto, medio, asesor, estado y acceso.
     Toda la tarjeta abre el detalle (enlace «estirado» del título); el botón de acceso (Unirse / Abrir
     WhatsApp) queda por encima con su propio destino, solo si la actividad está programada y tiene un
     enlace aplicable. $p (rejilla horaria): desplazamiento, duración, carril y tamaño. --}}
@php
    /** @var \Modules\Crm\Models\Activity $a */
    $start = $a->starts_at->copy()->setTimezone($tz);
    $end = $a->ends_at->copy()->setTimezone($tz);
    $related = $a->relatedName();
    $tone = \Modules\Crm\Support\Agenda\CalendarGrid::statusTone($a->status);
    $mediumIcon = \Modules\Crm\Support\Agenda\CalendarGrid::mediumIcon($a->medium);
    $range = $start->format('H:i').'–'.$end->format('H:i');
@endphp
<div @class(['ui-cal-ev', 'ui-tone--'.$tone, 'ui-cal-ev--'.($p['size'] ?? 'md'), 'is-'.$a->status->value, 'is-stacked' => ($p['n'] ?? 1) >= \Modules\Crm\Support\Agenda\CalendarGrid::CLUSTER]) wire:key="ag-ev-{{ $a->id }}-{{ $key ?? '' }}"
    @if ($p) style="--t:{{ $p['t'] }};--d:{{ $p['d'] }};--l:{{ $p['l'] }};--n:{{ $p['n'] }}" @endif
    title="{{ $range }} · {{ $a->title }}{{ $related ? ' · '.$related : '' }} · {{ $a->status->label() }}">
    <span class="ui-cal-ev-time">{{ $start->format('H:i') }}<span class="ui-cal-ev-end">–{{ $end->format('H:i') }}</span></span>
    <div class="ui-cal-ev-main">
        <div class="ui-cal-ev-head">
            <span class="ui-cal-ev-ic" aria-hidden="true"><x-ui.icon :name="\Modules\Crm\Support\Agenda\CalendarGrid::typeIcon($a->type)" /></span>
            <a href="{{ route('crm.agenda.show', $a) }}" class="ui-cal-ev-title ui-cal-open">{{ $a->title }}<span class="ui-sr">, {{ $a->type->label() }}, {{ $a->status->label() }}</span></a>
        </div>
        <div class="ui-cal-ev-meta">
            @if ($related)<span><x-ui.icon name="user" /> {{ $related }}</span>@endif
            <span>{{ $a->type->label() }}</span>
            @if ($mediumIcon)<span><x-ui.icon :name="$mediumIcon" /> {{ $a->medium->label() }}</span>@endif
            <span><x-ui.icon name="user-cog" /> {{ $a->assignee?->name ?? '—' }}</span>
            @if ($a->priority === 'high')<span class="is-high"><x-ui.icon name="alert-triangle" /> {{ __('Prioridad alta') }}</span>@endif
        </div>
    </div>
    <div class="ui-cal-ev-side">
        <x-ui.badge :tone="$tone" dot>{{ $a->status->label() }}</x-ui.badge>
        @include('crm::livewire.agenda.partials.join', ['a' => $a, 'compact' => false])
    </div>
</div>
