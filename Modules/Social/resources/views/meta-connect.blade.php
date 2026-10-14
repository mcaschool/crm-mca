<div class="mca-page">
    <x-ui.page-header :title="__('Meta')" :subtitle="__('Facebook · Messenger · Instagram')" :back="route('social.channels')" :backLabel="__('Canales')" />

    <div class="ui-stack ui-narrow">
        @if (! $this->platformReady())
            {{-- Plataforma Meta sin configurar: mensaje amigable, distinto por tipo de usuario.
                 Nunca se muestran detalles técnicos a un administrador de institución. --}}
            <div class="ui-callout" role="status">
                <x-ui.icon name="plug" />
                @if (auth()->user()?->isSuperAdmin())
                    <span>{{ __('Configuración de plataforma Meta pendiente.') }}</span>
                @else
                    <span>{{ __('La integración con Meta no está disponible temporalmente.') }}</span>
                @endif
            </div>
        @else
            {{-- ============================ PASO: INICIO ============================ --}}
            @if ($step === 'idle')
                <section class="ui-card" aria-labelledby="meta-idle-title">
                    <div class="ui-card-h">
                        <x-ui.icon name="plug" class="ui-card-ic" />
                        <h2 id="meta-idle-title">{{ __('Conectar Meta') }}</h2>
                        <span class="sp"></span>
                        @if ($connection !== null && $connection->usable())
                            <x-ui.badge tone="success" dot data-testid="meta-connected">{{ __('Conectado') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="neutral" dot>{{ __('No conectado') }}</x-ui.badge>
                        @endif
                    </div>
                    <div class="ui-card-p ui-stack">
                        <p class="ui-help">
                            {{ __('Conecta tu cuenta de Meta para detectar automáticamente tus Páginas de Facebook y tu cuenta de Instagram profesional, y usarlas en Formularios publicitarios. No tienes que buscar identificadores ni pegar tokens, y tus canales actuales no se modifican.') }}
                        </p>
                        @if ($connection !== null && $connection->usable())
                            <div class="ui-callout ui-callout--sm ui-tone--blue">
                                <x-ui.icon name="refresh" />
                                <span>{{ __('Volver a conectar sustituye la autorización solo si la nueva funciona.') }}</span>
                            </div>
                        @endif
                        @if ($errorMessage !== '')
                            <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $errorMessage }}</p>
                        @endif
                    </div>
                    @can('create', \Modules\Social\Models\SocialChannel::class)
                        <div class="ui-card-f ui-card-f--end">
                            @include('social::partials.meta-login-button', ['cfg' => $this->browserConfig(), 'action' => 'discover', 'idleLabel' => __('Conectar Meta'), 'btnClass' => 'ui-btn ui-btn--primary'])
                        </div>
                    @endcan
                </section>
            @endif

            {{-- ============================ PASO: SELECCIÓN ============================ --}}
            @if ($step === 'select')
                <section class="ui-card" aria-labelledby="meta-select-title">
                    <div class="ui-card-h">
                        <x-ui.icon name="building" class="ui-card-ic" />
                        <div class="ui-split-main">
                            <h2 id="meta-select-title">{{ __('Selecciona la cuenta que deseas conectar') }}</h2>
                            <span class="ui-cell-sub">{{ __('Esto es lo que detectamos con tu autorización de Meta.') }}</span>
                        </div>
                    </div>

                    @if ($pages === [])
                        <div class="ui-empty">
                            <div class="ui-empty-ic"><x-ui.icon name="search" /></div>
                            {{ __('No encontramos Páginas de Facebook en esta cuenta. Asegúrate de administrar al menos una Página y de haber autorizado el acceso.') }}
                        </div>
                    @else
                        <div class="ui-card-p ui-stack">
                            <div class="ui-grid" role="group" aria-labelledby="meta-select-title">
                                @foreach ($pages as $page)
                                    @php($isSel = $selectedPageId === $page['page_id'])
                                    <button type="button" wire:key="pg-{{ $page['page_id'] }}" wire:click="select('{{ $page['page_id'] }}')"
                                            class="ui-tpl-pick" aria-pressed="{{ $isSel ? 'true' : 'false' }}">
                                        <span class="ui-split">
                                            <span class="ui-entity-title">{{ $page['name'] }}</span>
                                            @if ($isSel)
                                                <x-ui.badge tone="blue" icon="check">{{ __('Seleccionada') }}</x-ui.badge>
                                            @endif
                                        </span>
                                        <span class="ui-person-line">
                                            @include('social::partials.provider-icon', ['provider' => 'messenger', 'size' => 16])
                                            <span class="ui-strong">Facebook / Messenger</span>
                                            <x-ui.badge tone="success" dot>{{ __('Detectado') }}</x-ui.badge>
                                        </span>
                                        <span class="ui-person-line">
                                            @include('social::partials.provider-icon', ['provider' => 'instagram', 'size' => 16])
                                            <span class="ui-strong">Instagram</span>
                                            @if ($page['has_instagram'])
                                                @if ($page['instagram_username'])<span class="ui-cell-sub">{{ '@'.$page['instagram_username'] }}</span>@endif
                                                <x-ui.badge tone="success" dot>{{ __('Detectado') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge tone="neutral" dot>{{ __('No detectado') }}</x-ui.badge>
                                            @endif
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            @if ($errorMessage !== '')
                                <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $errorMessage }}</p>
                            @endif
                        </div>
                        <div class="ui-card-f ui-card-f--end">
                            <x-ui.button variant="ghost" icon="refresh" wire:click="restart">{{ __('Empezar de nuevo') }}</x-ui.button>
                            <x-ui.button variant="primary" wire:click="confirm" :disabled="$selectedPageId === null">{{ __('Continuar') }}</x-ui.button>
                        </div>
                    @endif

                    @if ($pages === [] && $errorMessage !== '')
                        <div class="ui-card-p">
                            <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $errorMessage }}</p>
                        </div>
                    @endif
                </section>
            @endif

            {{-- ============================ PASO: HECHO ============================ --}}
            @if ($step === 'done')
                <section class="ui-card" aria-labelledby="meta-done-title">
                    <div class="ui-card-h">
                        <span class="ui-step-n is-done"><x-ui.icon name="check" /></span>
                        <h2 id="meta-done-title">{{ __('Conexión con Meta guardada') }}</h2>
                    </div>
                    <div class="ui-card-p ui-stack">
                        @if ($selectedPage)
                            <dl class="ui-meta">
                                <dt>{{ __('Cuenta') }}</dt>
                                <dd><strong>{{ $selectedPage['name'] }}</strong> — Facebook / Messenger{{ $selectedPage['has_instagram'] ? ' · Instagram '.($selectedPage['instagram_username'] ? '@'.$selectedPage['instagram_username'] : '') : '' }}</dd>
                            </dl>
                        @endif
                        <p class="ui-help">
                            {{ __('Tu empresa ya puede usar estas Páginas en Formularios publicitarios. Tus canales de Messenger e Instagram no se han modificado.') }}
                        </p>
                    </div>
                    <div class="ui-card-f ui-card-f--end">
                        <x-ui.button icon="refresh" wire:click="restart">{{ __('Volver a empezar') }}</x-ui.button>
                        <x-ui.button variant="primary" icon="file-text" :href="route('social.lead-forms')">{{ __('Ir a Formularios publicitarios') }}</x-ui.button>
                    </div>
                </section>
            @endif
        @endif
    </div>
</div>
