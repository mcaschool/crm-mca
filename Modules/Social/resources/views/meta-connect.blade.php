<div>
    <x-ui.styles />

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">Meta</h1>
                <p class="mca-sub">{{ __('Facebook · Messenger · Instagram') }}</p>
            </div>
            <div class="sp" style="flex:1"></div>
            <a href="{{ route('social.channels') }}" class="btn btn-soft btn-sm">
                <x-ui.icon name="plug" class="ic" style="width:15px;height:15px" /> {{ __('Canales') }}
            </a>
        </div>

        @if (! $this->platformReady())
            {{-- Plataforma Meta sin configurar: mensaje amigable, distinto por tipo de usuario.
                 Nunca se muestran detalles técnicos a un administrador de institución. --}}
            <div class="card card-p" style="display:flex;align-items:center;gap:12px;background:var(--mca-page-bg,#F4F6F9);margin-top:16px">
                <x-ui.icon name="plug" style="width:20px;height:20px;color:var(--muted)" />
                @if (auth()->user()?->isSuperAdmin())
                    <span style="font-size:13.5px;color:var(--ink-2,#3B4453)">{{ __('Configuración de plataforma Meta pendiente.') }}</span>
                @else
                    <span style="font-size:13.5px;color:var(--ink-2,#3B4453)">{{ __('La integración con Meta no está disponible temporalmente.') }}</span>
                @endif
            </div>
        @else
            {{-- ============================ PASO: INICIO ============================ --}}
            @if ($step === 'idle')
                <div class="card card-p" style="margin-top:16px;display:flex;flex-direction:column;gap:14px;align-items:flex-start">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px">
                            @if ($connection !== null && $connection->usable())
                                <span class="badge badge-on" data-testid="meta-connected">{{ __('Conectado') }}</span>
                                <span class="mca-help">{{ __('Volver a conectar sustituye la autorización solo si la nueva funciona.') }}</span>
                            @else
                                <span class="badge badge-off">{{ __('No conectado') }}</span>
                            @endif
                        </div>
                        <p class="mca-sub" style="margin-top:10px;max-width:520px">
                            {{ __('Conecta tu cuenta de Meta para detectar automáticamente tus Páginas de Facebook y tu cuenta de Instagram profesional, y usarlas en Formularios publicitarios. No tienes que buscar identificadores ni pegar tokens, y tus canales actuales no se modifican.') }}
                        </p>
                    </div>

                    @can('create', \Modules\Social\Models\SocialChannel::class)
                        @include('social::partials.meta-login-button', ['cfg' => $this->browserConfig(), 'action' => 'discover', 'idleLabel' => __('Conectar Meta'), 'btnClass' => 'btn btn-primary'])
                    @endcan

                    @if ($errorMessage !== '')
                        <span style="font-size:12.5px;color:#8A1C1C">{{ $errorMessage }}</span>
                    @endif
                </div>
            @endif

            {{-- ============================ PASO: SELECCIÓN ============================ --}}
            @if ($step === 'select')
                <div style="margin-top:18px">
                    <h2 style="font-size:15px;font-weight:700;margin:0 0 4px">{{ __('Selecciona la cuenta que deseas conectar') }}</h2>
                    <p class="mca-sub" style="margin-top:0">{{ __('Esto es lo que detectamos con tu autorización de Meta.') }}</p>

                    @if ($pages === [])
                        <div class="card card-p" style="margin-top:12px">
                            <p style="font-size:13.5px;color:var(--muted);margin:0">
                                {{ __('No encontramos Páginas de Facebook en esta cuenta. Asegúrate de administrar al menos una Página y de haber autorizado el acceso.') }}
                            </p>
                        </div>
                    @else
                        <div class="mca-grid" style="margin-top:12px">
                            @foreach ($pages as $page)
                                <button type="button" wire:key="pg-{{ $page['page_id'] }}" wire:click="select('{{ $page['page_id'] }}')"
                                        class="card card-p" style="text-align:left;cursor:pointer;border-width:2px;{{ $selectedPageId === $page['page_id'] ? 'border-color:var(--mca-blue,#1E5AA8)' : '' }}">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                                        <h3 style="font-size:14.5px;font-weight:700;margin:0">{{ $page['name'] }}</h3>
                                        @if ($selectedPageId === $page['page_id'])
                                            <span class="badge badge-on">{{ __('Seleccionada') }}</span>
                                        @endif
                                    </div>

                                    <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                                        <div style="display:flex;align-items:center;gap:8px;font-size:13px">
                                            @include('social::partials.provider-icon', ['provider' => 'messenger', 'size' => 16])
                                            <span style="font-weight:600">Facebook / Messenger</span>
                                            <span class="badge badge-on">{{ __('Detectado') }}</span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;font-size:13px">
                                            @include('social::partials.provider-icon', ['provider' => 'instagram', 'size' => 16])
                                            <span style="font-weight:600">Instagram</span>
                                            @if ($page['has_instagram'])
                                                <span style="color:var(--ink-2,#3B4453)">{{ $page['instagram_username'] ? '@'.$page['instagram_username'] : '' }}</span>
                                                <span class="badge badge-on">{{ __('Detectado') }}</span>
                                            @else
                                                <span class="badge badge-off">{{ __('No detectado') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </div>

                        <div style="margin-top:18px;display:flex;align-items:center;gap:12px">
                            <button type="button" wire:click="confirm" class="btn btn-primary" @disabled($selectedPageId === null)>{{ __('Continuar') }}</button>
                            <button type="button" wire:click="restart" class="btn btn-ghost">{{ __('Empezar de nuevo') }}</button>
                        </div>
                    @endif

                    @if ($errorMessage !== '')
                        <p style="font-size:12.5px;color:#8A1C1C;margin-top:10px">{{ $errorMessage }}</p>
                    @endif
                </div>
            @endif

            {{-- ============================ PASO: HECHO ============================ --}}
            @if ($step === 'done')
                <div class="card card-p" style="margin-top:18px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <x-ui.icon name="check" style="width:20px;height:20px;color:var(--mca-ok,#2E7D32)" />
                        <h2 style="font-size:15px;font-weight:700;margin:0">{{ __('Conexión con Meta guardada') }}</h2>
                    </div>
                    @if ($selectedPage)
                        <p class="mca-sub" style="margin-top:10px">
                            {{ __('Cuenta') }}: <strong>{{ $selectedPage['name'] }}</strong> — Facebook / Messenger{{ $selectedPage['has_instagram'] ? ' · Instagram '.($selectedPage['instagram_username'] ? '@'.$selectedPage['instagram_username'] : '') : '' }}
                        </p>
                    @endif
                    <p class="mca-sub" style="margin-top:6px">
                        {{ __('Tu empresa ya puede usar estas Páginas en Formularios publicitarios. Tus canales de Messenger e Instagram no se han modificado.') }}
                    </p>
                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
                        <a href="{{ route('social.lead-forms') }}" class="btn btn-primary btn-sm">{{ __('Ir a Formularios publicitarios') }}</a>
                        <button type="button" wire:click="restart" class="btn btn-soft btn-sm">{{ __('Volver a empezar') }}</button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
