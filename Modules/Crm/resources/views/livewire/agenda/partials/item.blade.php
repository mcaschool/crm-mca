{{-- Tarjeta compacta de actividad: hora, título, lead/contacto, asesor, medio y estado. --}}
@php
    /** @var \Modules\Crm\Models\Activity $a */
    $start = $a->starts_at->copy()->setTimezone($tz);
    $end = $a->ends_at->copy()->setTimezone($tz);
    $related = $a->relatedName();
@endphp
<a href="{{ route('crm.agenda.show', $a) }}" class="ag-item st-{{ $a->status->value }}" wire:key="ag-item-{{ $a->id }}-{{ $key ?? '' }}"
   title="{{ $a->title }} · {{ $a->status->label() }}">
    <div class="h">{{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ $a->medium->label() }}</div>
    <div class="t">{{ $a->title }}</div>
    @unless ($compact ?? false)
        @if ($related)<div class="s">{{ $related }}</div>@endif
        <div class="s">{{ $a->assignee?->name ?? '—' }} · {{ $a->status->label() }}</div>
    @endunless
</a>
