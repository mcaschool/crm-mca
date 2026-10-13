{{-- Un día del Calendario: cabecera (abre la vista día) y sus actividades. En la rejilla horaria es una
     columna; en la agenda (lista, tableta y móvil), una sección. $place: posiciones de CalendarGrid. --}}
@php
    /** @var \Illuminate\Support\Carbon $d */
    $key = $d->toDateString();
    $prefix = $keyPrefix ?? 'd';
@endphp
<section @class(['ui-cal-day', 'is-today' => $key === $todayLocal, 'is-empty' => $items->isEmpty()]) aria-labelledby="cal-{{ $prefix }}-{{ $key }}" wire:key="{{ $prefix }}-{{ $key }}">
    <h3 class="ui-cal-day-h" id="cal-{{ $prefix }}-{{ $key }}">
        <button type="button" class="ui-cal-daybtn" wire:click="goTo('{{ $key }}')" title="{{ __('Ver el día') }}">
            <span class="dow"><span class="s">{{ $d->translatedFormat('D') }}</span><span class="l">{{ $d->translatedFormat('l') }}</span></span>
            <span class="num">{{ $d->day }}</span>
            <span class="mon">{{ $d->translatedFormat(app()->getLocale() === 'es' ? '\d\e F' : 'F') }}@if ($d->year !== now($tz)->year) {{ $d->year }}@endif</span>
        </button>
        @if ($items->isNotEmpty())
            <span class="ui-cal-day-n">{{ trans_choice(':n actividad|:n actividades', $items->count(), ['n' => $items->count()]) }}</span>
        @endif
    </h3>
    <div class="ui-cal-day-b">
        @if (($now ?? null) !== null)
            <span class="ui-cal-now" style="--t:{{ $now }}" aria-hidden="true"></span>
        @endif
        {{-- Semana con espacio: 3 o más actividades a la vez se leen juntas en una tarjeta de grupo --}}
        @foreach ($clusters ?? [] as $c)
            <div class="ui-cal-cluster" style="--t:{{ $c['t'] }};--d:{{ $c['d'] }};--k:{{ count($c['ids']) }}" wire:key="cl-{{ $key }}-{{ $loop->index }}">
                <span class="ui-cal-cluster-h"><x-ui.icon name="layers" /> {{ __(':n actividades a la vez', ['n' => count($c['ids'])]) }}</span>
                <ul>
                    @foreach ($c['ids'] as $cid)
                        @php
                            $ca = $items->firstWhere('id', $cid);
                            $cs = $ca->starts_at->copy()->setTimezone($tz);
                        @endphp
                        <li @class(['ui-cal-chip', 'ui-tone--'.\Modules\Crm\Support\Agenda\CalendarGrid::statusTone($ca->status), 'is-'.$ca->status->value])
                            title="{{ $cs->format('H:i') }}–{{ $ca->ends_at->copy()->setTimezone($tz)->format('H:i') }} · {{ $ca->title }} · {{ $ca->status->label() }}">
                            <i aria-hidden="true"></i><time datetime="{{ $cs->toIso8601String() }}">{{ $cs->format('H:i') }}</time>
                            <a href="{{ route('crm.agenda.show', $ca) }}" class="ui-cal-open">{{ $ca->title }}<span class="ui-sr">, {{ $ca->status->label() }}</span></a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
        @forelse ($items as $a)
            @include('crm::livewire.agenda.partials.item', ['a' => $a, 'p' => $place[$a->id] ?? null, 'key' => $prefix])
        @empty
            @if (($empty ?? '') !== '')
                <p class="ui-cal-free">{{ $empty }}</p>
            @endif
        @endforelse
    </div>
</section>
