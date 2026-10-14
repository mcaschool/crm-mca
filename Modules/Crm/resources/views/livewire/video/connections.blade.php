<div class="mca-page">
    @php
        // Estado de la conexión institucional → [tono, texto]
        $stateOf = function ($i) {
            return match ($i?->status) {
                null => ['neutral', __('Sin configurar')],
                'active' => ['success', __('Conectada')],
                'pending' => ['warning', __('Pendiente')],
                'expired' => ['danger', __('Autorización caducada')],
                default => ['neutral', __('Desconectada')],
            };
        };
        // Estado de la sala de un usuario → [texto, tono]
        $roomLabel = fn ($r) => match ($r?->status) {
            null => [__('Sin sala'), 'neutral'],
            'active' => [__('Activa'), 'success'],
            'missing' => [__('No existe en el proveedor'), 'danger'],
            default => [__('Con error'), 'danger'],
        };
        $hostLabel = fn ($r) => match ($r?->host_status) {
            'assigned' => __('Coanfitrión asignado'),
            'failed' => __('Sin coanfitrión'),
            'advisor_host' => __('Anfitrión: el propio usuario (con licencia)'),
            'institutional_host' => __('Anfitrión técnico: cuenta institucional'),
            'not_host' => __('Anfitrión no reconocido'),
            default => '—',
        };
    @endphp

    <x-ui.page-header :title="__('Videoconferencias')" :subtitle="__('Una cuenta institucional de Google Meet y una de Zoom. Los usuarios nunca conectan cuentas propias: usan las salas que la administración les asigna.')" :back="route('integrations.index')" :back-label="__('Volver a integraciones')" />

    @if (session('video_status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('video_status') }}</div>
    @endif
    @if ($videoNotice !== '' && $videoTarget === '')
        <div @class(['ui-alert', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="{{ $videoNoticeError ? 'alert' : 'status' }}">
            <x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> {{ $videoNotice }}
        </div>
    @endif

    <div class="ui-stack">
        <div class="ui-grid ui-grid--2">
            {{-- Google Meet --}}
            @php([$gTone, $gText] = $stateOf($google))
            <form class="ui-card ui-entity" wire:key="vc-google" wire:submit="saveGoogle" novalidate>
                <div class="ui-card-h"><x-ui.icon name="video" class="ui-card-ic" /><h2>Google Meet</h2>
                    <span class="sp"></span><x-ui.badge :tone="$gTone" dot>{{ $gText }}</x-ui.badge>
                </div>
                <div class="ui-card-p ui-grow ui-stack">
                    <dl class="ui-meta">
                        <dt>{{ __('Cuenta') }}</dt><dd>{{ $google?->secret('account_email') ?: '—' }}</dd>
                        <dt>{{ __('Salas asignadas') }}</dt><dd>{{ $rooms->filter(fn ($r) => $r->provider === 'google_meet' && $r->status === 'active')->count() }}</dd>
                        <dt>{{ __('Última prueba') }}</dt>
                        <dd>
                            @if ($google?->last_tested_at)
                                {{ $google->last_tested_at->diffForHumans() }}
                                <x-ui.badge :tone="$google->last_test_ok ? 'success' : 'danger'" :icon="$google->last_test_ok ? 'check' : 'x'">{{ $google->last_test_ok ? __('Correcta') : __('Fallida') }}</x-ui.badge>
                            @else
                                <span class="t-nil">{{ __('nunca') }}</span>
                            @endif
                        </dd>
                    </dl>
                    @if ($google?->last_test_message)
                        <p class="ui-help">{{ $google->last_test_message }}</p>
                    @endif

                    <div class="ui-form-grid ui-form-grid--1">
                        <x-ui.field :label="__('ID de cliente OAuth')" for="vc-g-id" required :error="$errors->first('googleClientId')">
                            <input id="vc-g-id" type="text" wire:model="googleClientId" autocomplete="off" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('googleClientId')]) @error('googleClientId') aria-describedby="vc-g-id-error" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('Secreto de cliente OAuth')" for="vc-g-secret" :required="! ($googleMasked['client_secret'] ?? null)" :error="$errors->first('googleClientSecret')">
                            <input id="vc-g-secret" type="password" wire:model="googleClientSecret" autocomplete="off" placeholder="{{ __('Reemplazar credencial') }}" @class(['ui-input', 'is-invalid' => $errors->has('googleClientSecret')]) @if ($errors->has('googleClientSecret')) aria-describedby="vc-g-secret-error" @elseif ($googleMasked['client_secret'] ?? null) aria-describedby="vc-g-secret-now" @endif>
                            @if ($googleMasked['client_secret'] ?? null)
                                <p class="ui-help" id="vc-g-secret-now"><x-ui.icon name="lock" /> {{ __('Actual') }}: <span class="ui-mono">{{ $googleMasked['client_secret'] }}</span> — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}</p>
                            @endif
                        </x-ui.field>
                        <x-ui.field :label="__('Acceso a las salas')" for="vc-g-access" :error="$errors->first('googleAccessType')">
                            <select id="vc-g-access" wire:model="googleAccessType" class="ui-select">
                                <option value="TRUSTED">{{ __('Invitados externos piden acceso (recomendado)') }}</option>
                                <option value="OPEN">{{ __('Cualquiera con el enlace entra directamente') }}</option>
                            </select>
                        </x-ui.field>
                        <div class="ui-field">
                            <span class="ui-label">{{ __('URI de redirección autorizada en Google Cloud') }}</span>
                            <div class="ui-copy"><span class="ui-copy-text">{{ $redirectUri }}</span></div>
                        </div>
                    </div>
                </div>
                <div class="ui-card-f">
                    @if ($google !== null && $google->secret('refresh_token'))
                        <x-ui.button size="sm" icon="refresh" wire:click="testGoogle" wire:loading.attr="disabled">{{ __('Probar conexión') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" icon="power" wire:click="disconnectGoogle" wire:confirm="{{ __('¿Desconectar la cuenta institucional de Google Meet? Se revocará el acceso concedido.') }}">{{ __('Desconectar') }}</x-ui.button>
                    @endif
                    <span class="sp"></span>
                    @if ($google !== null && $google->secret('client_id'))
                        <x-ui.button size="sm" icon="plug" :href="route('integrations.video.google.connect')">{{ $google->status === 'active' ? __('Volver a conectar') : __('Conectar con Google') }}</x-ui.button>
                    @endif
                    <x-ui.button type="submit" size="sm" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar') }}</x-ui.button>
                </div>
            </form>

            {{-- Zoom --}}
            @php([$zTone, $zText] = $stateOf($zoom))
            <form class="ui-card ui-entity" wire:key="vc-zoom" wire:submit="saveZoom" novalidate>
                <div class="ui-card-h"><x-ui.icon name="video" class="ui-card-ic" /><h2>Zoom</h2>
                    <span class="sp"></span><x-ui.badge :tone="$zTone" dot>{{ $zText }}</x-ui.badge>
                </div>
                <div class="ui-card-p ui-grow ui-stack">
                    <dl class="ui-meta">
                        <dt>{{ __('Anfitrión institucional') }}</dt>
                        <dd>
                            {{ $zoom?->secret('account_email') ?: '—' }}
                            @if ($zoom?->secret('host_type') !== null)
                                · {{ (int) $zoom->secret('host_type') === 2 ? __('con licencia') : __('sin licencia') }}
                            @endif
                        </dd>
                        <dt>{{ __('Capacidad efectiva') }}</dt><dd>{{ trans_choice(':n reunión simultánea por anfitrión|:n reuniones simultáneas por anfitrión', $zoomLimit, ['n' => $zoomLimit]) }}</dd>
                        <dt>{{ __('Reuniones asignadas') }}</dt><dd>{{ $rooms->filter(fn ($r) => $r->provider === 'zoom' && $r->status === 'active')->count() }}</dd>
                        <dt>{{ __('Última prueba') }}</dt>
                        <dd>
                            @if ($zoom?->last_tested_at)
                                {{ $zoom->last_tested_at->diffForHumans() }}
                                <x-ui.badge :tone="$zoom->last_test_ok ? 'success' : 'danger'" :icon="$zoom->last_test_ok ? 'check' : 'x'">{{ $zoom->last_test_ok ? __('Correcta') : __('Fallida') }}</x-ui.badge>
                            @else
                                <span class="t-nil">{{ __('nunca') }}</span>
                            @endif
                        </dd>
                    </dl>
                    @if ($zoom?->last_test_message)
                        <p class="ui-help">{{ $zoom->last_test_message }}</p>
                    @endif

                    <div class="ui-form-grid">
                        <x-ui.field class="ui-span-full" :label="__('Account ID (Server-to-Server OAuth)')" for="vc-z-account" required :error="$errors->first('zoomAccountId')">
                            <input id="vc-z-account" type="text" wire:model="zoomAccountId" autocomplete="off" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('zoomAccountId')]) @error('zoomAccountId') aria-describedby="vc-z-account-error" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('Client ID')" for="vc-z-client" required :error="$errors->first('zoomClientId')">
                            <input id="vc-z-client" type="text" wire:model="zoomClientId" autocomplete="off" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('zoomClientId')]) @error('zoomClientId') aria-describedby="vc-z-client-error" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('Client Secret')" for="vc-z-secret" :required="! ($zoomMasked['client_secret'] ?? null)" :error="$errors->first('zoomClientSecret')">
                            <input id="vc-z-secret" type="password" wire:model="zoomClientSecret" autocomplete="off" placeholder="{{ __('Reemplazar credencial') }}" @class(['ui-input', 'is-invalid' => $errors->has('zoomClientSecret')]) @if ($errors->has('zoomClientSecret')) aria-describedby="vc-z-secret-error" @elseif ($zoomMasked['client_secret'] ?? null) aria-describedby="vc-z-secret-now" @endif>
                        </x-ui.field>
                        @if ($zoomMasked['client_secret'] ?? null)
                            <p class="ui-help ui-span-full" id="vc-z-secret-now"><x-ui.icon name="lock" /> {{ __('Actual') }}: <span class="ui-mono">{{ $zoomMasked['client_secret'] }}</span> — {{ __('deja el campo vacío para conservarla, o escribe una nueva para reemplazarla.') }}</p>
                        @endif
                        <x-ui.field class="ui-span-full" :label="__('Correo del anfitrión institucional (opcional)')" for="vc-z-host" :error="$errors->first('zoomHostEmail')">
                            <input id="vc-z-host" type="email" wire:model="zoomHostEmail" autocomplete="off" spellcheck="false" placeholder="{{ __('Vacío = propietario de la app') }}" @class(['ui-input', 'is-invalid' => $errors->has('zoomHostEmail')]) @error('zoomHostEmail') aria-describedby="vc-z-host-error" @enderror>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Reuniones simultáneas por anfitrión')" for="vc-z-cap" :error="$errors->first('zoomConcurrency')" :help="__('Según la licencia de Zoom: Pro admite 1; Business, Education y Enterprise, hasta 2. Los anfitriones alternativos cuentan para el mismo límite.')">
                            <select id="vc-z-cap" wire:model="zoomConcurrency" @class(['ui-select', 'is-invalid' => $errors->has('zoomConcurrency')]) aria-describedby="{{ $errors->has('zoomConcurrency') ? 'vc-z-cap-error ' : '' }}vc-z-cap-help">
                                @for ($n = 1; $n <= $zoomMax; $n++)
                                    <option value="{{ $n }}">{{ $n }}</option>
                                @endfor
                            </select>
                        </x-ui.field>
                    </div>
                </div>
                <div class="ui-card-f">
                    @if ($zoom !== null && $zoom->secret('client_secret'))
                        <x-ui.button size="sm" icon="refresh" wire:click="testZoom" wire:loading.attr="disabled">{{ __('Probar conexión') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" icon="power" wire:click="disconnectZoom" wire:confirm="{{ __('¿Desconectar la cuenta institucional de Zoom? Se borrará el secreto guardado.') }}">{{ __('Desconectar') }}</x-ui.button>
                    @endif
                    <span class="sp"></span>
                    <x-ui.button type="submit" size="sm" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar y probar') }}</x-ui.button>
                </div>
            </form>
        </div>

        {{-- Acceso a las reuniones del anfitrión institucional (decisión explícita) --}}
        @if ($zoom?->status === 'active')
            <section class="ui-card" aria-labelledby="vc-zaccess-title" wire:key="vc-zaccess">
                <div class="ui-card-h"><x-ui.icon name="lock" class="ui-card-ic" /><h2 id="vc-zaccess-title">{{ __('Acceso a las reuniones de Zoom sin licencia individual') }}</h2>
                    <span class="sp"></span><x-ui.badge :tone="$hostless ? 'warning' : 'success'" dot>{{ $hostless ? __('Sin anfitrión') : __('Con sala de espera') }}</x-ui.badge>
                </div>
                <p class="ui-card-intro">{{ __('Cada usuario sin licencia en Zoom recibe su propia reunión (id y enlace distintos) del anfitrión técnico institucional. Nadie del CRM es anfitrión de esas reuniones: solo quien entre con la cuenta institucional tiene los controles. Los usuarios con licencia en la cuenta son anfitriones de la suya y no se ven afectados.') }}</p>
                <div class="ui-card-p ui-stack">
                    <div class="ui-nets" role="radiogroup" aria-label="{{ __('Modalidad de acceso') }}">
                        <label class="ui-net"><input type="radio" wire:model.live="zoomAccess" value="waiting_room">
                            <span class="ui-net-text"><b>{{ __('Con sala de espera (recomendado)') }}</b>
                                <small>{{ __('Los invitados esperan hasta que alguien inicie la reunión con la cuenta institucional.') }}</small></span></label>
                        <label class="ui-net"><input type="radio" wire:model.live="zoomAccess" value="hostless">
                            <span class="ui-net-text"><b>{{ __('Acceso sin anfitrión') }}</b>
                                <small>{{ __('«Unirse antes que el anfitrión» activado y sala de espera desactivada.') }}</small></span></label>
                    </div>
                    @if ($zoomAccess === 'hostless' && ! $hostless)
                        <div class="ui-callout ui-callout--stack ui-tone--warning" data-testid="vc-hostless-risks">
                            <b><x-ui.icon name="alert-triangle" /> {{ __('Implicaciones de seguridad') }}</b>
                            <ul class="ui-callout-list">
                                <li>{{ __('Cualquiera que tenga el enlace (lleva la contraseña incluida) entra al momento, sin que nadie lo admita, incluso antes que el usuario del CRM.') }}</li>
                                <li>{{ __('Nadie tiene controles de anfitrión (silenciar a todos, expulsar, bloquear, grabar) salvo quien entre con la cuenta institucional.') }}</li>
                                <li>{{ __('Una reunión que siga abierta sin anfitrión ocupa la capacidad del anfitrión institucional e impide otras reuniones simultáneas; si ese anfitrión no tiene licencia, Zoom la corta a los 40 minutos.') }}</li>
                                <li>{{ __('Se mantienen la contraseña y la entrada silenciada. Si la cuenta de Zoom bloquea estas opciones, no se aplicarán y se indicará en cada sala.') }}</li>
                            </ul>
                            <label class="ui-check ui-check--top"><input type="checkbox" wire:model="zoomHostlessAck" @error('zoomHostlessAck') aria-invalid="true" aria-describedby="vc-ack-error" @enderror>
                                <span>{{ __('Entiendo estas implicaciones y decido activar el acceso sin anfitrión para las reuniones del anfitrión institucional.') }}</span></label>
                            @error('zoomHostlessAck') <p class="ui-error" id="vc-ack-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($hostlessAt)
                        <p class="ui-help">{{ __('Última decisión: :mode, por :name, :when.', ['mode' => $hostless ? __('acceso sin anfitrión') : __('sala de espera'), 'name' => $hostlessBy ?? '—', 'when' => $hostlessAt->diffForHumans()]) }}</p>
                    @endif
                    @if ($videoNotice !== '' && $videoTarget === 'zoom-access')
                        <div @class(['ui-callout', 'ui-callout--sm', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="{{ $videoNoticeError ? 'alert' : 'status' }}"><x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> <span>{{ $videoNotice }}</span></div>
                    @endif
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button size="sm" variant="primary" icon="check" wire:click="saveZoomAccess" wire:loading.attr="disabled" wire:target="saveZoomAccess"
                        :disabled="($zoomAccess === 'hostless') === $hostless">{{ __('Aplicar modalidad') }}</x-ui.button>
                </div>
            </section>
        @endif

        {{-- Salas por usuario --}}
        <section class="ui-card" aria-labelledby="vc-rooms-title">
            <div class="ui-card-h"><x-ui.icon name="users" class="ui-card-ic" /><h2 id="vc-rooms-title">{{ __('Salas permanentes por usuario') }}</h2></div>
            <p class="ui-card-intro">{{ __('Todos los usuarios activos con acceso al Calendario pueden tener su propia sala. Las actividades nuevas copian la sala del perfil; las ya creadas conservan su enlace y solo las futuras sin enlace pueden recibirla con «Aplicar a actividades sin enlace».') }}</p>
            <div class="mca-table-scroll">
                <table class="ui-table ui-table--stack-cells ui-table--flat">
                    <thead>
                        <tr><th>{{ __('Usuario') }}</th><th>Google Meet</th><th>Zoom</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($advisors as $advisor)
                            <tr wire:key="vc-adv-{{ $advisor->id }}">
                                <td class="rt-primary">
                                    <div class="ui-person-text">
                                        <span class="ui-cell-title">{{ $advisor->name }}</span>
                                        <span class="ui-cell-sub">{{ __($advisor->role?->label() ?? '') }}</span>
                                        @if (in_array($advisor->id, $withAgenda, true))
                                            <a href="{{ route('crm.agenda.profile', ['user' => $advisor->id]) }}" class="ui-link">{{ __('Perfil de reuniones') }}</a>
                                        @endif
                                    </div>
                                </td>
                                @foreach (['google_meet' => $google, 'zoom' => $zoom] as $provider => $conn)
                                    @php($room = $rooms->get($advisor->id.'|'.$provider))
                                    @php([$rText, $rTone] = $roomLabel($room))
                                    <td data-label="{{ $provider === 'zoom' ? 'Zoom' : 'Google Meet' }}">
                                        <div class="ui-cell-stack">
                                            <span class="ui-person-line">
                                                <x-ui.badge :tone="$rTone" :dot="$rTone === 'success'">{{ $rText }}</x-ui.badge>
                                                @if ($room)
                                                    <span class="t-2">{{ $hostLabel($room) }}</span>
                                                @endif
                                            </span>
                                            @if ($room && $room->last_checked_at)
                                                <span class="ui-cell-sub">{{ __('Revisada') }} {{ $room->last_checked_at->diffForHumans() }}</span>
                                            @endif
                                            @if ($room && $room->status !== 'active' && $room->last_error)
                                                <span class="ui-cell-sub ui-text-danger">{{ $room->last_error }}</span>
                                            @endif
                                            @if ($conn?->status === 'active')
                                                <div class="ui-actions-row">
                                                    @if ($room === null || $room->status !== 'active')
                                                        <x-ui.button size="sm" icon="plus" wire:click="generateRoom({{ $advisor->id }}, '{{ $provider }}')" wire:loading.attr="disabled">{{ __('Generar sala') }}</x-ui.button>
                                                    @else
                                                        <x-ui.button size="sm" icon="refresh" wire:click="checkRoom({{ $room->id }})" wire:loading.attr="disabled">{{ __('Revisar') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="generateRoom({{ $advisor->id }}, '{{ $provider }}', true)" wire:confirm="{{ __('¿Regenerar la sala? Las actividades ya creadas conservan el enlace anterior.') }}" wire:loading.attr="disabled">{{ __('Regenerar') }}</x-ui.button>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($videoNotice !== '' && $videoTarget === $advisor->id.'|'.$provider)
                                                <div @class(['ui-callout', 'ui-callout--sm', $videoNoticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="{{ $videoNoticeError ? 'alert' : 'status' }}" data-testid="vc-row-notice">
                                                    <x-ui.icon :name="$videoNoticeError ? 'alert-triangle' : 'check'" /> <span>{{ $videoNotice }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="rt-empty">
                                    <div class="ui-empty">
                                        <div class="ui-empty-ic"><x-ui.icon name="users" /></div>
                                        <b>{{ __('No hay usuarios activos con acceso al Calendario.') }}</b>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
