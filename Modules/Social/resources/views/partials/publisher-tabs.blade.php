{{-- Navegación del Publicador: Crear | Historial | Programadas | Calendario editorial (pestañas del kit). --}}
@php
    $tabs = [
        ['route' => 'social.publisher', 'label' => __('Crear publicación'), 'icon' => 'plus'],
        ['route' => 'social.publisher.history', 'label' => __('Historial'), 'icon' => 'activity'],
        ['route' => 'social.publisher.scheduled', 'label' => __('Programadas'), 'icon' => 'clock'],
        ['route' => 'social.publisher.calendar', 'label' => __('Calendario editorial'), 'icon' => 'calendar'],
    ];
    $currentPath = trim((string) \Livewire\Livewire::originalPath(), '/');
    $pendingCount = \Modules\Social\Models\SocialPost::query()->where('status', \Modules\Social\Models\SocialPost::SCHEDULED)->count();
@endphp
<nav aria-label="{{ __('Secciones del Publicador') }}">
    <div role="tablist" class="ui-tabs">
        @foreach ($tabs as $t)
            @php $on = request()->routeIs($t['route']) || trim((string) parse_url(route($t['route']), PHP_URL_PATH), '/') === $currentPath; @endphp
            <a href="{{ route($t['route']) }}" role="tab" class="ui-tab" aria-selected="{{ $on ? 'true' : 'false' }}" @if ($on) aria-current="page" @endif>
                <x-ui.icon :name="$t['icon']" /> {{ $t['label'] }}
                @if ($t['route'] === 'social.publisher.scheduled' && $pendingCount > 0)
                    <span class="ui-badge ui-badge--count ui-tone--blue">{{ $pendingCount }}<span class="ui-sr"> {{ __('programadas') }}</span></span>
                @endif
            </a>
        @endforeach
    </div>
</nav>
