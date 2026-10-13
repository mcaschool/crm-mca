{{--
  Selector (x-ui.kit). `filter` lo marca como filtro de listado; `active` (true cuando el
  filtro tiene un valor distinto de «todos») lo resalta para que se vea qué está aplicado.
--}}
@props(['filter' => false, 'active' => false, 'size' => 'md'])
<select {{ $attributes->merge(['class' => 'ui-select'.($filter ? ' ui-select--filter' : '').($filter && $active ? ' is-active' : '').($size === 'lg' ? ' ui-select--lg' : '')]) }}>{{ $slot }}</select>
