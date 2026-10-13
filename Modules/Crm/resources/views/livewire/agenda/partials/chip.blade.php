{{-- Actividad compacta de la vista mes: punto de estado, hora y título (abre el detalle) y acceso solo icono. --}}
@php
    /** @var \Modules\Crm\Models\Activity $a */
    $start = $a->starts_at->copy()->setTimezone($tz);
    $end = $a->ends_at->copy()->setTimezone($tz);
@endphp
<div @class(['ui-cal-chip', 'ui-tone--'.\Modules\Crm\Support\Agenda\CalendarGrid::statusTone($a->status), 'is-'.$a->status->value]) wire:key="ag-chip-{{ $a->id }}"
    title="{{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ $a->title }} · {{ $a->status->label() }}">
    <i aria-hidden="true"></i>
    <time datetime="{{ $start->toIso8601String() }}">{{ $start->format('H:i') }}</time>
    <a href="{{ route('crm.agenda.show', $a) }}" class="ui-cal-open">{{ $a->title }}<span class="ui-sr">, {{ $a->status->label() }}</span></a>
    @include('crm::livewire.agenda.partials.join', ['a' => $a, 'compact' => true])
</div>
