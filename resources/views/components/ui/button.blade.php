{{--
  Botón del sistema (x-ui.kit). Con `href` es un enlace; sin él, un <button>.
  variant: primary (una por zona) · secondary · ghost · danger. size: md (36px) · sm (32px).
  `icon` va delante del texto; sin texto (slot vacío) el botón es cuadrado y exige aria-label.
--}}
@props(['variant' => 'secondary', 'size' => 'md', 'icon' => null, 'href' => null, 'type' => 'button'])
@php
    $iconOnly = $slot->isEmpty();
    $classes = 'ui-btn ui-btn--'.$variant.($size === 'sm' ? ' ui-btn--sm' : '').($iconOnly ? ' ui-btn--icon' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}</button>
@endif
