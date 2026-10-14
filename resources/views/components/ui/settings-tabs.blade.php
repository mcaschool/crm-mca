{{--
  Cabecera + pestañas de AJUSTES. Ajustes es un CONTENEDOR de secciones de
  configuración (marca/logo, remitentes de correo, y las que se añadan). Cada
  sección es su propia pantalla, pero comparten esta cabecera para leerse como una
  sola pantalla de configuración. Solo-Admin (el gating vive en cada componente).
  Para añadir una sección futura: agrega una entrada a $tabs.
  Marcado del kit: x-ui.page-header + .ui-tabs / .ui-tab (role="tablist"/"tab", aria-selected).
--}}
@php
    $tabs = [
        ['label' => __('General'), 'route' => 'settings.index', 'icon' => 'building'],
        ['label' => __('Remitentes de correo'), 'route' => 'settings.email-senders', 'icon' => 'mail'],
        ['label' => __('Plantillas de correo'), 'route' => 'settings.email-templates', 'icon' => 'file-text'],
    ];
    // En las actualizaciones de Livewire la petición es /livewire/update: la pestaña activa
    // se deduce de la ruta original de la página para que no se pierda tras cada acción.
    $currentPath = trim((string) \Livewire\Livewire::originalPath(), '/');
@endphp
<x-ui.page-header :title="__('Ajustes')" :subtitle="__('Configuración del panel: marca institucional, remitentes de correo y más.')" />

<nav aria-label="{{ __('Secciones de Ajustes') }}">
    <div role="tablist" class="ui-tabs">
        @foreach ($tabs as $t)
            @php $on = request()->routeIs($t['route']) || trim((string) parse_url(route($t['route']), PHP_URL_PATH), '/') === $currentPath; @endphp
            <a href="{{ route($t['route']) }}" role="tab" class="ui-tab" aria-selected="{{ $on ? 'true' : 'false' }}" @if ($on) aria-current="page" @endif>
                <x-ui.icon :name="$t['icon']" /> {{ $t['label'] }}
            </a>
        @endforeach
    </div>
</nav>
