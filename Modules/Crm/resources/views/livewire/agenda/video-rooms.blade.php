<section class="ui-card" aria-labelledby="vr-title">
    <div class="ui-card-h"><x-ui.icon name="video" class="ui-card-ic" /><h2 id="vr-title">{{ __('Salas institucionales') }}</h2></div>
    @if ($videoNotice !== '')
        <div class="ui-card-p">
            <div @class(['ui-alert', 'ui-alert--flush', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="status">
                <x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> {{ $videoNotice }}
            </div>
        </div>
    @endif
    <div class="ui-vr-grid">
        @foreach ($blocks as $provider => $b)
            @php($room = $b['room'])
            @php($isZoom = $provider === 'zoom')
            <div class="ui-vr" wire:key="vr-{{ $provider }}">
                <div class="ui-person-line">
                    <h3 class="ui-vr-title">{{ $isZoom ? 'Zoom' : 'Google Meet' }}</h3>
                    @if ($room === null)
                        <x-ui.badge tone="neutral">{{ __('Sin sala') }}</x-ui.badge>
                    @elseif ($room->status === 'active')
                        <x-ui.badge tone="success" dot>{{ $isZoom ? __('Reunión activa') : __('Sala activa') }}</x-ui.badge>
                    @elseif ($room->status === 'missing')
                        <x-ui.badge tone="danger">{{ __('No existe en el proveedor') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="danger">{{ __('Con error') }}</x-ui.badge>
                    @endif
                </div>

                <dl class="ui-meta">
                    <dt>{{ __('Enlace permanente') }}</dt>
                    <dd>
                        @if ($b['link'])
                            <span class="ui-mono ui-break">{{ $b['link'] }}</span>
                            <x-ui.badge :tone="$b['inProfile'] ? 'blue' : 'neutral'">{{ $b['inProfile'] ? __('sala institucional') : __('enlace manual') }}</x-ui.badge>
                        @else
                            <span class="t-nil">{{ __('sin enlace') }}</span>
                        @endif
                    </dd>
                    @if ($room)
                        @if ($isZoom)
                            <dt>{{ __('Permiso para dirigirla') }}</dt>
                            <dd>{{ $room->host_status === 'advisor_host' ? __('Sí: el asesor es el anfitrión') : __('No: el asesor no es el anfitrión') }}</dd>
                        @else
                            <dt>{{ __('Asignación') }}</dt>
                            <dd>{{ $room->host_status === 'assigned' ? __('Coanfitrión asignado') : __('Sin coanfitrión') }}</dd>
                        @endif
                        @if ($room->last_checked_at)
                            <dt>{{ __('Revisada') }}</dt><dd>{{ $room->last_checked_at->diffForHumans() }}</dd>
                        @endif
                    @endif
                </dl>

                @if ($room && $room->host_note && ! $room->advisorCanHost())
                    <div class="ui-callout ui-callout--sm ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ $room->host_note }}</span></div>
                @endif
                @if ($room && $b['manual'])
                    <div class="ui-callout ui-callout--sm ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ __('El perfil usa un enlace manual. Se conserva hasta que la administración lo reemplace por la sala institucional.') }}</span></div>
                @endif

                <div class="ui-actions-row">
                    @if ($b['link'])
                        <a href="{{ $b['link'] }}" target="_blank" rel="noopener noreferrer" class="ui-btn ui-btn--secondary ui-btn--sm"><x-ui.icon name="external-link" /> {{ $isZoom ? __('Abrir Zoom') : __('Abrir Google Meet') }}</a>
                    @endif
                    @if ($isAdmin && $b['connected'])
                        @if ($room === null || $room->status !== 'active')
                            <x-ui.button size="sm" variant="primary" wire:click="generateFor('{{ $provider }}')" wire:loading.attr="disabled">{{ $isZoom ? __('Generar reunión de Zoom') : __('Generar sala de Google Meet') }}</x-ui.button>
                        @else
                            <x-ui.button size="sm" icon="refresh" wire:click="checkRoom({{ $room->id }})" wire:loading.attr="disabled">{{ __('Revisar') }}</x-ui.button>
                            @if ($b['manual'])
                                <x-ui.button size="sm" wire:click="assignRoom({{ $room->id }})" wire:confirm="{{ __('¿Reemplazar el enlace manual del perfil por la sala institucional? Las actividades ya creadas conservan su enlace.') }}">{{ __('Usar la sala en el perfil') }}</x-ui.button>
                            @endif
                            @if ($b['pending'] > 0)
                                <x-ui.button size="sm" wire:click="applyRoom({{ $room->id }})" wire:confirm="{{ __('¿Poner esta sala en las actividades futuras que aún no tienen enlace? Se avisará a sus invitados.') }}">{{ __('Aplicar a actividades sin enlace (:n)', ['n' => $b['pending']]) }}</x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="generateFor('{{ $provider }}', true)" wire:confirm="{{ __('¿Regenerar la sala? Las actividades ya creadas conservan el enlace anterior.') }}" wire:loading.attr="disabled">{{ __('Regenerar') }}</x-ui.button>
                        @endif
                    @elseif ($isAdmin)
                        <x-ui.button size="sm" variant="ghost" icon="plug" :href="route('integrations.video')">{{ __('Conectar la cuenta institucional') }}</x-ui.button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
