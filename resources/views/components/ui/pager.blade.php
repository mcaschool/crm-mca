{{--
  Paginación de un listado Livewire (x-ui.kit): «Mostrando X–Y de Z» + anterior/siguiente.
  Usa previousPage/nextPage del trait WithPagination del componente.
--}}
@props(['paginator'])
@if ($paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'ui-pager']) }}>
        <span>{{ __('Mostrando') }} <b>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</b> {{ __('de') }} <b>{{ $paginator->total() }}</b></span>
        <div class="ui-pager-nav">
            <x-ui.button size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$paginator->onFirstPage()" aria-label="{{ __('Página anterior') }}" />
            <span class="ui-pager-pos">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <x-ui.button size="sm" icon="chevron-right" wire:click="nextPage" :disabled="! $paginator->hasMorePages()" aria-label="{{ __('Página siguiente') }}" />
        </div>
    </div>
@endif
