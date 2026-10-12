{{-- Acceso directo a la reunión: el enlace permanente guardado en la actividad (Meet/Zoom) o el wa.me ya
     implementado (WhatsApp). Solo actividades programadas con un enlace HTTPS aplicable; si no, nada. --}}
@php
    /** @var \Modules\Crm\Models\Activity $a */
    $joinUrl = $a->isScheduled() ? $a->joinUrl() : null;
    $joinUrl = is_string($joinUrl) && str_starts_with($joinUrl, 'https://') ? $joinUrl : null;
    $isWhatsapp = $a->medium === \Modules\Crm\Enums\ActivityMedium::WhatsApp;
    $joinLabel = $isWhatsapp ? __('Abrir WhatsApp') : __('Unirse');
@endphp
@if ($joinUrl)
    <a href="{{ $joinUrl }}" target="_blank" rel="noopener noreferrer" class="ag-join {{ ($compact ?? false) ? 'only-ic' : '' }}"
       title="{{ $joinLabel }}" aria-label="{{ $joinLabel }}: {{ $a->title }}">
        <x-ui.icon name="{{ $isWhatsapp ? 'message-circle' : 'video' }}" class="i12" />@unless ($compact ?? false)<span>{{ $joinLabel }}</span>@endunless
    </a>
@endif
