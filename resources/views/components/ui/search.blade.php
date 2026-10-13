{{--
  Buscador (x-ui.kit). Los atributos (wire:model, placeholder, aria-label…) van al <input>.
--}}
@props(['size' => 'md'])
<div class="ui-search">
    <x-ui.icon name="search" />
    <input type="search" {{ $attributes->merge(['class' => 'ui-input'.($size === 'lg' ? ' ui-input--lg' : '')]) }}>
</div>
