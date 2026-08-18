{{--
  x-ui.funnel — embudo de estados nativo (CSS puro, cero dependencias). Uso dentro
  de `.mca-home` (los estilos .funnel/.fn viven en x-ui.home-styles).

  Props:
    segments      array de ['label' => string, 'value' => int, 'class' => 'st-new'|'st-con'|'st-seg'|'st-mat'|'st-des']
    emptyMessage  texto del estado vacío
--}}
@props(['segments' => [], 'emptyMessage' => 'Aún no hay leads para el embudo.'])
@php
    $segments = collect($segments);
    $max = max(1, (int) $segments->max('value'));
    $hasData = $segments->sum('value') > 0;
@endphp
@if ($hasData)
    <div class="funnel">
        @foreach ($segments as $s)
            <div class="fn {{ $s['class'] }}">
                <span class="fl">{{ $s['label'] }}</span>
                <div class="ft"><div class="ff" style="width:{{ (int) $s['value'] > 0 ? max(8, (int) round((int) $s['value'] / $max * 100)) : 0 }}%">{{ (int) $s['value'] > 0 ? (int) $s['value'] : '' }}</div></div>
            </div>
        @endforeach
    </div>
@else
    <div class="empty">{{ $emptyMessage }}</div>
@endif
