<section class="ui-card" aria-labelledby="vr-title">
    <div class="ui-card-h"><x-ui.icon name="video" class="ui-card-ic" /><h2 id="vr-title">{{ __('Salas institucionales') }}</h2></div>
    @if ($videoNotice !== '' && $videoTarget === '')
        <div class="ui-card-p">
            <div @class(['ui-alert', 'ui-alert--flush', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="status">
                <x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> {{ $videoNotice }}
            </div>
        </div>
    @endif
    <div class="ui-vr-grid">
        @foreach ($blocks as $provider => $b)
            @php
                $room = $b['room'];
                $isZoom = $provider === 'zoom';
                // «Mi sala de Zoom»: solo para la propia persona (nunca se inicia la reunión de otra).
                $mine = $isZoom && $isOwn;
                $guestLink = $room?->isActive() ? $room->join_url : $b['link'];
            @endphp
            <div class="ui-vr" wire:key="vr-{{ $provider }}" @if ($mine) data-testid="my-zoom-room" @endif>
                <div class="ui-person-line">
                    <h3 class="ui-vr-title">{{ $mine ? __('Mi sala de Zoom') : ($isZoom ? 'Zoom' : 'Google Meet') }}</h3>
                    @if ($room === null)
                        <x-ui.badge tone="neutral">{{ __('Sin sala') }}</x-ui.badge>
                    @elseif ($room->status === 'active')
                        <x-ui.badge tone="success" dot>{{ $isZoom ? __('Reunión activa') : __('Sala activa') }}</x-ui.badge>
                    @elseif ($room->status === 'missing')
                        <x-ui.badge tone="danger">{{ __('No existe en el proveedor') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="danger">{{ __('Con error') }}</x-ui.badge>
                    @endif
                    @if ($isZoom && $room?->isActive())
                        <x-ui.badge :tone="$room->secure === true ? 'success' : ($room->secure === false ? 'danger' : 'neutral')" :icon="$room->secure === true ? 'lock' : null">{{ $room->secure === true ? __('Protegida') : ($room->secure === false ? __('Sin proteger') : __('Sin verificar')) }}</x-ui.badge>
                    @endif
                </div>

                <dl class="ui-meta">
                    <dt>{{ $mine ? __('Enlace para invitados') : __('Enlace permanente') }}</dt>
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
                            <dt>{{ __('Anfitrión') }}</dt>
                            <dd>{{ match ($room->host_status) {
                                'advisor_host' => $mine ? __('Tú, con tu licencia de Zoom') : __('El propio usuario, con su licencia de Zoom'),
                                'institutional_host' => __('Identidad técnica institucional (sin licencia individual)'),
                                default => __('No reconocido: la reunión no es de este usuario'),
                            } }}</dd>
                        @else
                            <dt>{{ __('Asignación') }}</dt>
                            <dd>{{ $room->host_status === 'assigned' ? __('Coanfitrión asignado') : __('Sin coanfitrión') }}</dd>
                        @endif
                        @if ($room->last_checked_at)
                            <dt>{{ __('Revisada') }}</dt><dd>{{ $room->last_checked_at->diffForHumans() }}</dd>
                        @endif
                    @endif
                </dl>

                @if ($mine && $room === null)
                    <div class="ui-callout ui-callout--sm"><x-ui.icon name="shield" /> <span>{{ __('Aún no tienes sala de Zoom. La administración debe generarla en Integraciones › Videoconferencias.') }}</span></div>
                @elseif ($mine && $room->host_status === 'institutional_host')
                    <div class="ui-callout ui-callout--sm ui-tone--blue"><x-ui.icon name="shield" />
                        <span>{{ trans_choice('Al iniciarla actúas con la identidad técnica institucional: tienes los controles de anfitrión en tu reunión y admites a los invitados desde la sala de espera. Todas las salas sin licencia comparten su capacidad: :limit reunión a la vez.|Al iniciarla actúas con la identidad técnica institucional: tienes los controles de anfitrión en tu reunión y admites a los invitados desde la sala de espera. Todas las salas sin licencia comparten su capacidad: :limit reuniones a la vez.', $zoomLimit, ['limit' => $zoomLimit]) }}</span></div>
                @elseif ($mine && $room->host_status === 'advisor_host')
                    <div class="ui-callout ui-callout--sm ui-tone--blue"><x-ui.icon name="shield" /> <span>{{ __('Eres el anfitrión de tu reunión con tu licencia de Zoom y admites a los invitados desde la sala de espera.') }}</span></div>
                @endif
                @if ($room && $room->host_note && ! $room->advisorCanHost() && ! $room->canStartFromCrm())
                    <div class="ui-callout ui-callout--sm ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ $room->host_note }}</span></div>
                @endif
                @if ($room && $b['manual'])
                    <div class="ui-callout ui-callout--sm ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ __('El perfil usa un enlace manual. Se conserva hasta que la administración lo reemplace por la sala institucional.') }}</span></div>
                @endif

                @if ($mine)
                    @php
                        $startReason = match (true) {
                            ! $b['connected'] => __('La conexión institucional de Zoom no está activa.'),
                            $room === null => __('Aún no tienes sala de Zoom.'),
                            ! $room->canStartFromCrm() => __('Tu sala aún no está protegida o verificada: la administración debe aplicar la configuración segura.'),
                            ! $hostStartEnabled => __('La administración aún no ha habilitado el inicio de reuniones desde el CRM.'),
                            default => null,
                        };
                    @endphp
                    <div class="ui-actions-row ui-vr-start" x-data="{ copied: false }">
                        @if ($startReason === null)
                            <form method="POST" action="{{ route('crm.video.zoom.start') }}" target="_blank" rel="noopener" data-testid="zoom-start-form">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--primary"><x-ui.icon name="video" /> {{ __('Iniciar mi reunión como anfitrión') }}</button>
                            </form>
                        @else
                            <button type="button" class="ui-btn ui-btn--primary" disabled aria-describedby="vr-start-why-{{ $provider }}"><x-ui.icon name="video" /> {{ __('Iniciar mi reunión como anfitrión') }}</button>
                        @endif
                        @if ($guestLink)
                            <button type="button" class="ui-btn ui-btn--secondary" x-on:click="navigator.clipboard.writeText(@js($guestLink)); copied = true; setTimeout(() => copied = false, 1800)">
                                <x-ui.icon name="file-text" /> <span x-text="copied ? @js(__('Enlace copiado')) : @js(__('Copiar enlace para invitados'))">{{ __('Copiar enlace para invitados') }}</span>
                            </button>
                        @endif
                    </div>
                    @if ($startReason !== null)
                        <p class="ui-help" id="vr-start-why-{{ $provider }}">{{ $startReason }}</p>
                    @endif
                    @if ($startError)
                        <div class="ui-callout ui-callout--sm ui-tone--danger" role="alert" data-testid="zoom-start-error"><x-ui.icon name="alert-triangle" /> <span>{{ $startError }}</span></div>
                    @endif
                @endif

                <div class="ui-actions-row">
                    @if ($b['link'] && ! $mine)
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
                @if ($videoNotice !== '' && $videoTarget === $ownerId.'|'.$provider)
                    <div @class(['ui-callout', 'ui-callout--sm', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="{{ $videoNoticeError ? 'alert' : 'status' }}" data-testid="vr-notice">
                        <x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> <span>{{ $videoNotice }}</span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</section>
