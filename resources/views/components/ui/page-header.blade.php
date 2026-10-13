{{--
  Cabecera de página estándar: enlace «volver» opcional, título (con contador opcional),
  descripción y acciones a la derecha (la principal, la última). Estilos en x-ui.shell-styles
  (.mca-pagehead), válidos en cualquier familia de página.
--}}
@props(['title', 'subtitle' => null, 'count' => null, 'back' => null, 'backLabel' => null])
<div {{ $attributes->merge(['class' => 'mca-pagehead']) }}>
    <div class="ph-text">
        @if ($back)
            <a href="{{ $back }}" class="ph-back"><x-ui.icon name="chevron-left" class="ic" /> {{ $backLabel ?? __('Volver') }}</a>
        @endif
        <h1>{{ $title }}@if ($count !== null)<span class="ph-count">{{ $count }}</span>@endif</h1>
        @if ($subtitle)
            <p class="ph-sub">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="ph-actions">{{ $actions }}</div>
    @endisset
</div>
