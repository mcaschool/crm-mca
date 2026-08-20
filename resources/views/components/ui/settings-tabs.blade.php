{{--
  Cabecera + pestañas de AJUSTES. Ajustes es un CONTENEDOR de secciones de
  configuración (marca/logo, remitentes de correo, y las que se añadan). Cada
  sección es su propia pantalla, pero comparten esta cabecera para leerse como una
  sola pantalla de configuración. Solo-Admin (el gating vive en cada componente).
  Para añadir una sección futura: agrega una entrada a $tabs.
--}}
@php
    $tabs = [
        ['label' => 'General', 'route' => 'settings.index'],
        ['label' => 'Remitentes de correo', 'route' => 'settings.email-senders'],
        ['label' => 'Plantillas de correo', 'route' => 'settings.email-templates'],
    ];
@endphp
<div class="mca-head">
    <div>
        <h1 class="mca-h1">{{ __('Ajustes') }}</h1>
        <p class="mca-sub">{{ __('Configuración del panel: marca institucional, remitentes de correo y más.') }}</p>
    </div>
</div>

<div role="tablist" style="display:flex;gap:2px;border-bottom:1px solid var(--line);margin:6px 0 20px;flex-wrap:wrap">
    @foreach ($tabs as $t)
        @php $on = request()->routeIs($t['route']); @endphp
        <a href="{{ route($t['route']) }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}"
            style="padding:9px 15px;font-size:13.5px;font-weight:600;text-decoration:none;margin-bottom:-1px;border-bottom:2px solid {{ $on ? 'var(--mca, #1E5AA8)' : 'transparent' }};color:{{ $on ? 'var(--ink, #13253D)' : 'var(--muted, #61748F)' }}">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
