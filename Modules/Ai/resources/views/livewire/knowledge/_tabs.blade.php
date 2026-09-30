{{-- Pestañas del Centro de Conocimiento (segmentadas), compartidas por Biblioteca y Por agente.
     Cada vista pasa su pestaña en $active ('library' | 'agents'): no se deduce de la ruta, porque
     tras una acción de Livewire la petición es la interna de Livewire y no coincidiría. --}}
@php
    $kcTabs = [
        'library' => ['label' => __('Biblioteca'), 'route' => 'ai.knowledge.library', 'icon' => 'book-open'],
        'agents' => ['label' => __('Por agente'), 'route' => 'ai.knowledge.agents', 'icon' => 'users'],
    ];
@endphp
<nav class="kc-seg" role="tablist" aria-label="{{ __('Secciones del Centro de Conocimiento') }}">
    @foreach ($kcTabs as $key => $t)
        @php $on = ($active ?? null) === $key; @endphp
        <a href="{{ route($t['route']) }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" @class(['on' => $on])>
            <x-ui.icon name="{{ $t['icon'] }}" /> {{ $t['label'] }}
        </a>
    @endforeach
</nav>
