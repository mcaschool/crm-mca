{{-- Tarjeta compacta de actividad: hora, título, lead/contacto, asesor, medio y estado. Toda la tarjeta abre
     el detalle (enlace «estirado» del título); el botón de acceso (Unirse / Abrir WhatsApp) queda por encima
     con su propio destino, solo si la actividad está programada y tiene un enlace aplicable. --}}
@php
    /** @var \Modules\Crm\Models\Activity $a */
    $start = $a->starts_at->copy()->setTimezone($tz);
    $end = $a->ends_at->copy()->setTimezone($tz);
    $related = $a->relatedName();
@endphp
<div class="ag-item st-{{ $a->status->value }}" wire:key="ag-item-{{ $a->id }}-{{ $key ?? '' }}"
     title="{{ $a->title }} · {{ $a->status->label() }}">
    <div class="h">{{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ $a->medium->label() }}</div>
    <a href="{{ route('crm.agenda.show', $a) }}" class="t ag-open">{{ $a->title }}</a>
    @unless ($compact ?? false)
        @if ($related)<div class="s">{{ $related }}</div>@endif
        <div class="s">{{ $a->assignee?->name ?? '—' }} · {{ $a->status->label() }}</div>
    @endunless
    @include('crm::livewire.agenda.partials.join', ['a' => $a, 'compact' => $compact ?? false])
</div>
