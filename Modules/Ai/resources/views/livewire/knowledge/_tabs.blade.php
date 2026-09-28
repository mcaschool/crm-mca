{{-- Pestañas del Centro de Conocimiento (compartidas por Biblioteca y Agentes). --}}
@php
    $kcTabs = [
        ['label' => __('Biblioteca'), 'route' => 'ai.knowledge.library'],
        ['label' => __('Por agente'), 'route' => 'ai.knowledge.agents'],
    ];
@endphp
<div role="tablist" style="display:flex;gap:2px;border-bottom:1px solid var(--line);margin:6px 0 20px;flex-wrap:wrap">
    @foreach ($kcTabs as $t)
        @php $on = request()->routeIs($t['route']); @endphp
        <a href="{{ route($t['route']) }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}"
            style="padding:9px 15px;font-size:13.5px;font-weight:600;text-decoration:none;margin-bottom:-1px;border-bottom:2px solid {{ $on ? 'var(--mca,#1E5AA8)' : 'transparent' }};color:{{ $on ? 'var(--ink,#13253D)' : 'var(--muted,#61748F)' }}">{{ $t['label'] }}</a>
    @endforeach
</div>
