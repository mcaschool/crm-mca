{{--
  Grupo de filtros de un listado (x-ui.kit). En escritorio los controles quedan en línea en
  la barra de herramientas; en móvil (<768 px) se pliegan tras un botón «Filtros» que dice
  cuántos hay aplicados (`active`), para que el listado empiece arriba. Alpine viene con
  Livewire; `wire:ignore.self` conserva abierto/cerrado cuando Livewire re-renderiza.
--}}
@props(['active' => 0])
<div {{ $attributes->merge(['class' => 'ui-filters']) }} x-data="{ open: false }" :class="{ 'is-open': open }" wire:ignore.self>
    <button type="button" class="ui-btn ui-btn--secondary ui-filters-toggle" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
        <x-ui.icon name="filter" /> {{ __('Filtros') }}
        @if ($active > 0)<span class="ui-badge ui-badge--count ui-tone--blue">{{ $active }}<span class="ui-sr"> {{ __('aplicados') }}</span></span>@endif
        <x-ui.icon name="chevron-down" class="ui-filters-chev" />
    </button>
    <div class="ui-filters-body">{{ $slot }}</div>
</div>
