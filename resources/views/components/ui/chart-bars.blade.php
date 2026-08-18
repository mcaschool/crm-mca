{{--
  x-ui.chart-bars — barras horizontales nativas (CSS puro, cero dependencias).
  Uso dentro de `.mca-home` (los estilos .bars/.bar-row viven en x-ui.home-styles).

  Props:
    rows          array de ['label' => string, 'value' => int]
    emptyMessage  texto del estado vacío
--}}
@props(['rows' => [], 'emptyMessage' => 'Aún no hay datos para mostrar.'])
@php
    $rows = collect($rows);
    $max = max(1, (int) $rows->max('value'));
    $hasData = $rows->sum('value') > 0;
@endphp
@if ($hasData)
    <div class="bars">
        @foreach ($rows as $r)
            <div class="bar-row">
                <span class="bl">{{ $r['label'] }}</span>
                <div class="bar-track"><div class="bar-fill" style="width:{{ max(2, (int) round((int) $r['value'] / $max * 100)) }}%"></div></div>
                <span class="bv">{{ (int) $r['value'] }}</span>
            </div>
        @endforeach
    </div>
@else
    <div class="empty">{{ $emptyMessage }}</div>
@endif
