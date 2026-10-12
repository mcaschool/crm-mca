<div>
    <x-ui.styles />
    <style>
        .vc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,370px),1fr));gap:16px;align-items:start}
        .vc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
        .vc-head h3{font-size:15px;font-weight:700;color:var(--ink);margin:0;display:flex;align-items:center;gap:8px}
        .vc-meta{margin-top:8px;font-size:13px;color:var(--muted);display:grid;gap:3px}
        .vc-meta b{color:var(--ink);font-weight:600}
        .vc-actions{margin-top:14px;display:flex;flex-wrap:wrap;gap:8px}
        .vc-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(0,2fr) minmax(0,2fr);gap:14px;padding:12px 0;border-top:1px solid var(--line);align-items:start}
        .vc-row:first-of-type{border-top:0}
        .vc-cell{min-width:0;font-size:13px;color:var(--muted)}
        .vc-cell .lbl{display:none;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3,#8A99B2);margin-bottom:4px}
        .vc-cell .btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
        .vc-note{font-size:12px;margin-top:4px;color:var(--ink-3,#8A99B2);overflow-wrap:anywhere}
        .vc-mono{font-family:ui-monospace,monospace;font-size:12px;overflow-wrap:anywhere}
        .vc-th{display:grid;grid-template-columns:minmax(140px,1fr) minmax(0,2fr) minmax(0,2fr);gap:14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3,#8A99B2);padding-bottom:6px}
        @media (max-width:760px){
            .vc-row{grid-template-columns:1fr;gap:10px}
            .vc-th{display:none}
            .vc-cell .lbl{display:block}
        }
    </style>
    @php
        $stateOf = function ($i) {
            return match ($i?->status) {
                null => ['off', __('Sin configurar')],
                'active' => ['on', __('Conectada')],
                'pending' => ['warn', __('Pendiente')],
                'expired' => ['err', __('Autorización caducada')],
                default => ['off', __('Desconectada')],
            };
        };
        $badge = fn ($tone) => match ($tone) {
            'on' => 'badge badge-on',
            'off' => 'badge badge-off',
            default => 'badge',
        };
        $badgeStyle = fn ($tone) => match ($tone) {
            'warn' => 'background:var(--mca-warn-soft);color:var(--mca-warn)',
            'err' => 'background:#FDECEA;color:#b3261e',
            default => '',
        };
        $roomLabel = fn ($r) => match ($r?->status) {
            null => [__('Sin sala'), 'off'],
            'active' => [__('Activa'), 'on'],
            'missing' => [__('No existe en el proveedor'), 'err'],
            default => [__('Con error'), 'err'],
        };
        $hostLabel = fn ($r) => match ($r?->host_status) {
            'assigned' => __('Coanfitrión asignado'),
            'failed' => __('Sin coanfitrión'),
            'advisor_host' => __('El asesor es anfitrión'),
            'not_host', 'institutional_host' => __('El asesor no es anfitrión'),
            default => '—',
        };
    @endphp

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('integrations.index') }}" class="btn btn-ghost btn-sm" title="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ __('Videoconferencias') }}</h1>
                    <p class="mca-sub">{{ __('Una cuenta institucional de Google Meet y una de Zoom. Los asesores nunca conectan cuentas propias: usan las salas que la administración les asigna.') }}</p>
                </div>
            </div>
        </div>

        @if (session('video_status'))
            <div class="mca-toast ok" role="status"><x-ui.icon name="check" class="ic" /> {{ session('video_status') }}</div>
        @endif
        @if ($videoNotice !== '')
            <div class="mca-toast {{ $videoNoticeError ? 'err' : 'ok' }}" role="status">
                <x-ui.icon name="{{ $videoNoticeError ? 'alert-triangle' : 'check' }}" class="ic" /> {{ $videoNotice }}
            </div>
        @endif

        <div class="vc-grid">
            {{-- Google Meet --}}
            @php([$gTone, $gText] = $stateOf($google))
            <div class="card card-p fade" wire:key="vc-google">
                <div class="vc-head">
                    <h3><x-ui.icon name="video" class="ic" style="width:17px;height:17px" /> Google Meet</h3>
                    <span class="{{ $badge($gTone) }}" style="{{ $badgeStyle($gTone) }}">{{ $gText }}</span>
                </div>
                <div class="vc-meta">
                    <div>{{ __('Cuenta') }}: <b>{{ $google?->secret('account_email') ?: '—' }}</b></div>
                    <div>{{ __('Salas asignadas') }}: <b>{{ $rooms->filter(fn ($r) => $r->provider === 'google_meet' && $r->status === 'active')->count() }}</b></div>
                    <div>
                        {{ __('Última prueba') }}:
                        @if ($google?->last_tested_at)
                            {{ $google->last_tested_at->diffForHumans() }} <span style="color:{{ $google->last_test_ok ? 'var(--mca-ok)' : '#b3261e' }}">{{ $google->last_test_ok ? '✓' : '✕' }}</span>
                        @else
                            {{ __('nunca') }}
                        @endif
                    </div>
                    @if ($google?->last_test_message)
                        <div class="vc-note">{{ $google->last_test_message }}</div>
                    @endif
                </div>

                <form wire:submit="saveGoogle" style="margin-top:14px">
                    <div class="field">
                        <label for="vc-g-id">{{ __('ID de cliente OAuth') }}</label>
                        <input id="vc-g-id" type="text" wire:model="googleClientId" autocomplete="off">
                        @error('googleClientId') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-g-secret">{{ __('Secreto de cliente OAuth') }}</label>
                        @if ($googleMasked['client_secret'] ?? null)
                            <p class="mca-help" style="margin:0 0 6px">{{ __('Actual') }}: <span class="vc-mono">{{ $googleMasked['client_secret'] }}</span> — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}</p>
                        @endif
                        <input id="vc-g-secret" type="password" wire:model="googleClientSecret" autocomplete="off" placeholder="{{ __('Reemplazar credencial') }}">
                        @error('googleClientSecret') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-g-access">{{ __('Acceso a las salas') }}</label>
                        <select id="vc-g-access" wire:model="googleAccessType">
                            <option value="TRUSTED">{{ __('Invitados externos piden acceso (recomendado)') }}</option>
                            <option value="OPEN">{{ __('Cualquiera con el enlace entra directamente') }}</option>
                        </select>
                    </div>
                    <p class="mca-help" style="margin-top:0">{{ __('URI de redirección autorizada en Google Cloud') }}: <span class="vc-mono">{{ $redirectUri }}</span></p>
                    <div class="vc-actions">
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">{{ __('Guardar') }}</button>
                        @if ($google !== null && $google->secret('client_id'))
                            <a href="{{ route('integrations.video.google.connect') }}" class="btn btn-soft btn-sm">{{ $google->status === 'active' ? __('Volver a conectar') : __('Conectar con Google') }}</a>
                        @endif
                        @if ($google !== null && $google->secret('refresh_token'))
                            <button type="button" wire:click="testGoogle" wire:loading.attr="disabled" class="btn btn-ghost btn-sm">{{ __('Probar conexión') }}</button>
                            <button type="button" wire:click="disconnectGoogle" wire:confirm="{{ __('¿Desconectar la cuenta institucional de Google Meet? Se revocará el acceso concedido.') }}" class="btn btn-ghost btn-sm">{{ __('Desconectar') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Zoom --}}
            @php([$zTone, $zText] = $stateOf($zoom))
            <div class="card card-p fade" wire:key="vc-zoom">
                <div class="vc-head">
                    <h3><x-ui.icon name="video" class="ic" style="width:17px;height:17px" /> Zoom</h3>
                    <span class="{{ $badge($zTone) }}" style="{{ $badgeStyle($zTone) }}">{{ $zText }}</span>
                </div>
                <div class="vc-meta">
                    <div>{{ __('Anfitrión institucional') }}: <b>{{ $zoom?->secret('account_email') ?: '—' }}</b>
                        @if ($zoom?->secret('host_type') !== null)
                            · {{ (int) $zoom->secret('host_type') === 2 ? __('con licencia') : __('sin licencia') }}
                        @endif
                    </div>
                    <div>{{ __('Capacidad efectiva') }}: <b>{{ trans_choice(':n reunión simultánea por anfitrión|:n reuniones simultáneas por anfitrión', $zoomLimit, ['n' => $zoomLimit]) }}</b></div>
                    <div>{{ __('Reuniones asignadas') }}: <b>{{ $rooms->filter(fn ($r) => $r->provider === 'zoom' && $r->status === 'active')->count() }}</b></div>
                    <div>
                        {{ __('Última prueba') }}:
                        @if ($zoom?->last_tested_at)
                            {{ $zoom->last_tested_at->diffForHumans() }} <span style="color:{{ $zoom->last_test_ok ? 'var(--mca-ok)' : '#b3261e' }}">{{ $zoom->last_test_ok ? '✓' : '✕' }}</span>
                        @else
                            {{ __('nunca') }}
                        @endif
                    </div>
                    @if ($zoom?->last_test_message)
                        <div class="vc-note">{{ $zoom->last_test_message }}</div>
                    @endif
                </div>

                <form wire:submit="saveZoom" style="margin-top:14px">
                    <div class="field">
                        <label for="vc-z-account">{{ __('Account ID (Server-to-Server OAuth)') }}</label>
                        <input id="vc-z-account" type="text" wire:model="zoomAccountId" autocomplete="off">
                        @error('zoomAccountId') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-z-client">{{ __('Client ID') }}</label>
                        <input id="vc-z-client" type="text" wire:model="zoomClientId" autocomplete="off">
                        @error('zoomClientId') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-z-secret">{{ __('Client Secret') }}</label>
                        @if ($zoomMasked['client_secret'] ?? null)
                            <p class="mca-help" style="margin:0 0 6px">{{ __('Actual') }}: <span class="vc-mono">{{ $zoomMasked['client_secret'] }}</span> — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}</p>
                        @endif
                        <input id="vc-z-secret" type="password" wire:model="zoomClientSecret" autocomplete="off" placeholder="{{ __('Reemplazar credencial') }}">
                        @error('zoomClientSecret') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-z-host">{{ __('Correo del anfitrión institucional (opcional)') }}</label>
                        <input id="vc-z-host" type="email" wire:model="zoomHostEmail" autocomplete="off" placeholder="{{ __('Vacío = propietario de la app') }}">
                        @error('zoomHostEmail') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="vc-z-cap">{{ __('Reuniones simultáneas por anfitrión') }}</label>
                        <select id="vc-z-cap" wire:model="zoomConcurrency">
                            @for ($n = 1; $n <= $zoomMax; $n++)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endfor
                        </select>
                        <p class="mca-help">{{ __('Según la licencia de Zoom: Pro admite 1; Business, Education y Enterprise, hasta 2. Los anfitriones alternativos cuentan para el mismo límite.') }}</p>
                        @error('zoomConcurrency') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="vc-actions">
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">{{ __('Guardar y probar') }}</button>
                        @if ($zoom !== null && $zoom->secret('client_secret'))
                            <button type="button" wire:click="testZoom" wire:loading.attr="disabled" class="btn btn-ghost btn-sm">{{ __('Probar conexión') }}</button>
                            <button type="button" wire:click="disconnectZoom" wire:confirm="{{ __('¿Desconectar la cuenta institucional de Zoom? Se borrará el secreto guardado.') }}" class="btn btn-ghost btn-sm">{{ __('Desconectar') }}</button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        {{-- Salas por asesor --}}
        <div class="card card-p fade" style="margin-top:16px">
            <div class="vc-head">
                <h3>{{ __('Salas permanentes por asesor') }}</h3>
            </div>
            <p class="mca-help" style="margin:6px 0 12px">{{ __('Las actividades nuevas copian la sala del perfil. Las ya creadas conservan su enlace; solo las futuras sin enlace pueden recibirla con «Aplicar a actividades sin enlace».') }}</p>
            <div class="vc-th"><div>{{ __('Asesor') }}</div><div>Google Meet</div><div>Zoom</div></div>
            @forelse ($advisors as $advisor)
                <div class="vc-row" wire:key="vc-adv-{{ $advisor->id }}">
                    <div class="vc-cell" style="color:var(--ink);font-weight:600">
                        {{ $advisor->name }}
                        <div><a href="{{ route('crm.agenda.profile', ['user' => $advisor->id]) }}" style="font-size:12px;font-weight:600;color:var(--mca-blue,#1E5AA8);text-decoration:none">{{ __('Perfil de reuniones') }}</a></div>
                    </div>
                    @foreach (['google_meet' => $google, 'zoom' => $zoom] as $provider => $conn)
                        @php($room = $rooms->get($advisor->id.'|'.$provider))
                        @php([$rText, $rTone] = $roomLabel($room))
                        <div class="vc-cell">
                            <div class="lbl">{{ $provider === 'zoom' ? 'Zoom' : 'Google Meet' }}</div>
                            <span class="{{ $badge($rTone) }}" style="{{ $badgeStyle($rTone) }}">{{ $rText }}</span>
                            @if ($room)
                                <span style="margin-left:6px">{{ $hostLabel($room) }}</span>
                                @if ($room->last_checked_at)
                                    <div class="vc-note">{{ __('Revisada') }} {{ $room->last_checked_at->diffForHumans() }}</div>
                                @endif
                            @endif
                            @if ($conn?->status === 'active')
                                <div class="btns">
                                    @if ($room === null || $room->status !== 'active')
                                        <button type="button" class="btn btn-soft btn-sm" wire:click="generateRoom({{ $advisor->id }}, '{{ $provider }}')" wire:loading.attr="disabled">{{ __('Generar sala') }}</button>
                                    @else
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="checkRoom({{ $room->id }})" wire:loading.attr="disabled">{{ __('Revisar') }}</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="generateRoom({{ $advisor->id }}, '{{ $provider }}', true)" wire:confirm="{{ __('¿Regenerar la sala? Las actividades ya creadas conservan el enlace anterior.') }}" wire:loading.attr="disabled">{{ __('Regenerar') }}</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="mca-help">{{ __('No hay asesores activos.') }}</p>
            @endforelse
        </div>
    </div>
</div>
