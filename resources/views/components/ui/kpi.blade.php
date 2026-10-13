{{--
  Indicador (x-ui.kit): etiqueta + icono en tono arriba, cifra grande abajo. Con `href`
  toda la tarjeta enlaza a su listado filtrado. `trend` (entero, %) muestra la variación;
  `accent` aplica el acento dorado institucional (uno por panel, como máximo).
--}}
@props(['label', 'value', 'icon' => 'activity', 'tone' => 'blue', 'href' => null, 'trend' => null, 'accent' => false])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'ui-kpi'.($accent ? ' ui-kpi--accent' : '')]) }}>
    <div class="ui-kpi-top">
        <span class="ui-kpi-ic ui-tone--{{ $tone }}"><x-ui.icon :name="$icon" /></span>
        <span class="ui-kpi-label">{{ $label }}</span>
        @if ($href)<x-ui.icon name="chevron-right" class="ui-kpi-go" />@endif
    </div>
    <div class="ui-kpi-value">
        {{ $value }}
        @if ($trend !== null)
            <span @class(['ui-kpi-trend', 'down' => $trend < 0])><x-ui.icon :name="$trend < 0 ? 'chevron-down' : 'chevron-up'" />{{ $trend >= 0 ? '+' : '' }}{{ $trend }}%</span>
        @endif
    </div>
</{{ $tag }}>
