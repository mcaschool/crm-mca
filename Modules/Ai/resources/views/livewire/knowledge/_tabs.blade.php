{{-- Pestañas del Centro de Conocimiento (segmentadas), compartidas por Biblioteca y Por agente. --}}
@php
    $kcTabs = [
        ['label' => __('Biblioteca'), 'route' => 'ai.knowledge.library', 'icon' => 'book-open'],
        ['label' => __('Por agente'), 'route' => 'ai.knowledge.agents', 'icon' => 'users'],
    ];
@endphp
<nav class="kc-seg" role="tablist" aria-label="{{ __('Secciones del Centro de Conocimiento') }}">
    @foreach ($kcTabs as $t)
        @php $on = request()->routeIs($t['route']); @endphp
        <a href="{{ route($t['route']) }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" @class(['on' => $on])>
            <x-ui.icon name="{{ $t['icon'] }}" /> {{ $t['label'] }}
        </a>
    @endforeach
</nav>
