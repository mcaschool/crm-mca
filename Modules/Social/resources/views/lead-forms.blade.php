@php
    use Modules\Social\Support\MetaLeadAccessGuidance as Guide;
    $selectedPages = $pages->where('selected', true);
    $chosenForms = $selectedPages->flatMap(fn ($p) => $forms->get($p->id, collect())->where('is_active', true));
    $stepState = fn (int $n) => $steps['done'][$n] ? 'done' : ($steps['current'] === $n ? 'current' : 'pending');
    $stepLabel = ['done' => __('Hecho'), 'current' => __('Siguiente paso'), 'pending' => __('Pendiente')];
    $stepTone = ['done' => 'success', 'current' => 'blue', 'pending' => 'neutral'];
    $mark = fn (?string $s) => ['ok' => '✓', 'fail' => '✗'][$s] ?? '–';
    $markClass = fn (?string $s) => ['ok' => 'is-ok', 'fail' => 'is-fail'][$s] ?? '';
@endphp
<div class="mca-page">
    <x-ui.page-header :title="__('Formularios publicitarios')" :subtitle="__('Los contactos que dejan sus datos en los anuncios de Facebook e Instagram entran directamente al CRM, con su programa, su asesor y la campaña de origen.')" :back="route('social.channels')" :backLabel="__('Volver')" />

    <div class="ui-stack">
        <ol class="ui-steps" aria-label="{{ __('Pasos') }}" data-testid="wizard-steps">
            @foreach ([1 => __('Conectar Meta'), 2 => __('Elegir Página y formularios'), 3 => __('Asignar destino'), 4 => __('Comprobar acceso'), 5 => __('Activar recepción')] as $n => $title)
                <li class="is-{{ $stepState($n) }}" data-step="{{ $n }}" data-state="{{ $stepState($n) }}" @if ($stepState($n) === 'current') aria-current="step" @endif><span class="ui-step-n is-{{ $stepState($n) }}">@if ($steps['done'][$n])<x-ui.icon name="check" />@else{{ $n }}@endif</span>{{ $title }}</li>
            @endforeach
        </ol>

        @if ($notice)
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ $notice }}</div>
        @endif
        @if ($error)
            <div class="ui-alert ui-alert--flush ui-tone--danger" role="alert" data-testid="lead-forms-error"><x-ui.icon name="alert-triangle" /> {{ $error }}</div>
        @endif
        @if ($killSwitch)
            <div class="ui-alert ui-alert--flush ui-tone--warning" role="status"><x-ui.icon name="clock" />
                {{ __('La recepción de formularios está detenida temporalmente por la plataforma. Tu configuración se conserva.') }}
            </div>
        @endif

        {{-- ───────── Gestión de la plataforma (solo operador) ───────── --}}
        @if ($isOperator && $platformIssues->isNotEmpty())
            <section class="ui-card" data-testid="platform-issues">
                <div class="ui-card-h"><x-ui.icon name="shield" class="ui-card-ic" /><h2>{{ __('Gestión de la plataforma') }}</h2></div>
                <div class="ui-card-p ui-stack">
                    <p class="ui-help">{{ __('Permisos que Meta deniega a la aplicación del CRM (afectan a todas las empresas; no los resuelve cada cliente):') }}</p>
                    <ul class="ui-checklist">
                        @foreach ($platformIssues as $perm => $count)
                            <li><b>·</b><strong>{{ $perm }}</strong> — {{ trans_choice(':n Página afectada|:n Páginas afectadas', $count, ['n' => $count]) }}</li>
                        @endforeach
                    </ul>
                    <div class="ui-callout ui-callout--sm ui-tone--deep"><x-ui.icon name="shield" /><span>{{ Guide::forOperator(['area' => 'app', 'who' => 'platform', 'permissions' => $platformIssues->keys()->all()]) }}</span></div>
                </div>
            </section>
        @endif

        {{-- ═════════ 1. Conectar Meta ═════════ --}}
        <section class="ui-card" data-testid="meta-connection">
            <div class="ui-card-h"><span class="ui-step-n is-{{ $stepState(1) }}">1</span><h2>{{ __('Conectar Meta') }}</h2><span class="sp"></span><x-ui.badge :tone="$stepTone[$stepState(1)]">{{ $stepLabel[$stepState(1)] }}</x-ui.badge></div>
            <div class="ui-card-p ui-stack">
                @if (! $platformReady)
                    <div class="ui-callout ui-callout--sm"><x-ui.icon name="plug" /><span>{{ $isOperator ? __('Configuración de plataforma Meta pendiente.') : __('La integración con Meta no está disponible temporalmente.') }}</span></div>
                @endif
                @php($state = $connection?->effectiveStatus())
                <div class="ui-split">
                    <div class="ui-split-main">
                        @if ($connection === null || $state === 'disconnected')
                            <span class="ui-person-line"><x-ui.badge tone="neutral" dot>{{ __('Sin conexión') }}</x-ui.badge></span>
                            <p class="ui-help">{{ __('Autoriza a tu empresa en Meta: verás tus Páginas sin copiar identificadores ni tokens.') }}</p>
                        @else
                            <span class="ui-person-line"><x-ui.badge :tone="['active' => 'success', 'expiring' => 'warning', 'expired' => 'danger', 'invalid' => 'danger'][$state] ?? 'neutral'" dot data-testid="connection-state">{{ [
                                'active' => __('Conectada'), 'expiring' => __('Renovar pronto'), 'expired' => __('Caducada'), 'invalid' => __('No válida'),
                            ][$state] ?? $state }}</x-ui.badge></span>
                            <p class="ui-help">
                                {{ __('Conectada por :who el :date.', ['who' => $connection->connectedBy?->name ?? '—', 'date' => $connection->connected_at?->format('d/m/Y')]) }}
                                {{ $connection->renewBy() ? __('Renovar antes del :date.', ['date' => $connection->renewBy()->format('d/m/Y')]) : __('Sin caducidad.') }}
                            </p>
                        @endif
                    </div>
                    <div class="ui-actions-row">
                        @if ($platformReady)
                            @php($fresh = $connection === null || $state === 'disconnected')
                            {{-- La clave cambia con el estado: el botón (wire:ignore) se reconstruye con su texto nuevo. --}}
                            <span wire:key="meta-login-{{ $fresh ? 'connect' : 'reconnect' }}">
                                @include('social::partials.meta-login-button', [
                                    'cfg' => $this->browserConfig(), 'action' => 'connect',
                                    'idleLabel' => $fresh ? __('Conectar Meta') : __('Reconectar Meta'),
                                    'btnClass' => $fresh ? 'ui-btn ui-btn--primary ui-btn--sm' : 'ui-btn ui-btn--secondary ui-btn--sm',
                                ])
                            </span>
                        @endif
                        @if ($connection !== null && $state !== 'disconnected')
                            <x-ui.button size="sm" icon="refresh" wire:click="refreshConnection">{{ __('Revisar conexión') }}</x-ui.button>
                            <x-ui.button size="sm" variant="danger" icon="power" wire:click="disconnect" wire:confirm="{{ __('¿Desconectar Meta? La recepción de contactos se detendrá; la configuración se conserva.') }}">{{ __('Desconectar') }}</x-ui.button>
                        @endif
                    </div>
                </div>
                @if (in_array($state, ['expiring', 'expired', 'invalid'], true))
                    <p class="ui-callout ui-callout--sm ui-callout--stack ui-tone--warning"><b>{{ __('Lo resuelve tu empresa') }}</b><span>{{ __('La autorización de Meta caduca o dejó de ser válida. Pulsa «Reconectar Meta» y vuelve a autorizar; tu configuración se conserva.') }}</span></p>
                @endif
            </div>
        </section>

        {{-- ═════════ 2. Elegir Página y formularios ═════════ --}}
        <section class="ui-card" data-testid="step-pages">
            <div class="ui-card-h"><span class="ui-step-n is-{{ $stepState(2) }}">2</span><h2>{{ __('Elegir Página y formularios') }}</h2><span class="sp"></span><x-ui.badge :tone="$stepTone[$stepState(2)]">{{ $stepLabel[$stepState(2)] }}</x-ui.badge></div>
            @if (! $steps['done'][1] && $pages->isEmpty())
                <p class="ui-list-empty">{{ __('Primero conecta Meta: aquí aparecerán tus Páginas.') }}</p>
            @elseif ($pages->isEmpty())
                <p class="ui-list-empty">{{ __('Tu conexión con Meta no incluye ninguna Página. Pulsa «Reconectar Meta» y selecciona tus Páginas al autorizar.') }}</p>
            @endif
            @if ($pages->isNotEmpty())
                <ul class="ui-list">
                    @foreach ($pages as $page)
                        <li class="ui-list-item ui-list-item--col" data-testid="lead-page-{{ $page->id }}" wire:key="lp-{{ $page->id }}">
                            <div class="ui-split">
                                <div class="ui-split-main">
                                    <span class="ui-person-line">
                                        <span class="ui-list-title">{{ $page->name }}</span>
                                        @if (! $page->available)
                                            <x-ui.badge tone="neutral">{{ __('No incluida en tu conexión') }}</x-ui.badge>
                                        @elseif ($page->selected)
                                            <x-ui.badge tone="success" dot>{{ __('Usada para formularios') }}</x-ui.badge>
                                        @elseif (in_array($page->id, $takenElsewhere, true))
                                            <x-ui.badge tone="warning">{{ __('La usa otra empresa') }}</x-ui.badge>
                                        @endif
                                    </span>
                                </div>
                                <div class="ui-actions-row">
                                    @if ($page->selected)
                                        <x-ui.button size="sm" icon="refresh" wire:click="sync({{ $page->id }})">{{ __('Actualizar formularios') }}</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="selectPage({{ $page->id }}, false)" wire:confirm="{{ __('¿Dejar de usar esta Página? Dejará de recibir contactos.') }}">{{ __('Dejar de usar') }}</x-ui.button>
                                    @elseif (in_array($page->id, $takenElsewhere, true))
                                        @if ($page->fullControl() && $transferPageId !== $page->id)
                                            <x-ui.button size="sm" variant="primary" icon="arrow-right-left" wire:click="askTransfer({{ $page->id }})">{{ __('Transferir a mi empresa') }}</x-ui.button>
                                        @endif
                                    @elseif ($page->available)
                                        <x-ui.button size="sm" variant="primary" icon="check" wire:click="selectPage({{ $page->id }}, true)">{{ __('Usar para formularios') }}</x-ui.button>
                                    @endif
                                </div>
                            </div>
                            @if ($transferPageId === $page->id)
                                <div class="ui-callout ui-callout--stack ui-tone--deep" data-testid="transfer-confirm">
                                    <b>{{ __('Confirmar la transferencia') }}</b>
                                    <ul class="ui-callout-list">
                                        <li>{{ __('El CRM comprobará ahora con Meta que quien conectó Meta en tu empresa tiene control total de la Página.') }}</li>
                                        <li>{{ __('La otra empresa dejará de recibir sus contactos de inmediato; conserva los que ya recibió y verá el aviso.') }}</li>
                                        <li>{{ __('En tu empresa la recepción quedará apagada hasta que compruebes el acceso.') }}</li>
                                        <li>{{ __('Quedará registrado en la auditoría de las dos empresas.') }}</li>
                                    </ul>
                                    <label class="ui-label" for="lf-transfer-{{ $page->id }}">{{ __('Escribe el nombre de la Página para confirmar:') }} <strong>{{ $page->name }}</strong></label>
                                    <div class="ui-actions-row">
                                        <input id="lf-transfer-{{ $page->id }}" type="text" wire:model="transferConfirmation" aria-label="{{ __('Nombre de la Página') }}" class="ui-input">
                                        <x-ui.button size="sm" variant="primary" wire:click="confirmTransfer">{{ __('Transferir') }}</x-ui.button>
                                        <x-ui.button size="sm" wire:click="cancelTransfer">{{ __('Cancelar') }}</x-ui.button>
                                    </div>
                                </div>
                            @endif
                            @if (! $page->selected && in_array($page->id, $takenElsewhere, true) && ! $page->fullControl())
                                <p class="ui-help">{{ __('Para transferirla, quien tenga control total de la Página en Meta debe conectar Meta desde tu empresa, o la otra empresa debe dejar de usarla.') }}</p>
                            @endif
                            @if ($page->released_at && ! $page->selected)
                                <p class="ui-callout ui-callout--sm ui-callout--stack ui-tone--deep" data-testid="page-released-{{ $page->id }}"><b>{{ __('La Página pasó a otra empresa') }}</b><span>{{ __('El :date otra empresa con control total de la Página en Meta la transfirió. Los contactos que ya recibiste siguen en tu CRM.', ['date' => $page->released_at->format('d/m/Y')]) }}</span></p>
                            @endif
                            @if ($page->selected)
                                <ul class="ui-list ui-list--boxed">
                                    @forelse ($forms->get($page->id, collect()) as $form)
                                        <li class="ui-list-item ui-list-item--wrap" wire:key="ch-{{ $form->id }}">
                                            <span class="ui-list-main">{{ $form->name }}</span>
                                            <span class="ui-list-aside">
                                                <x-ui.button size="sm" :variant="$form->is_active ? 'primary' : 'secondary'" :icon="$form->is_active ? 'check' : null" :aria-pressed="$form->is_active ? 'true' : 'false'" wire:click="chooseForm({{ $form->id }}, {{ $form->is_active ? 'false' : 'true' }})">{{ $form->is_active ? __('Elegido') : __('Elegir') }}</x-ui.button>
                                            </span>
                                        </li>
                                    @empty
                                        <li class="ui-list-empty">{{ __('Sin formularios todavía: pulsa «Actualizar formularios» (o «Comprobar acceso», que también los trae).') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ═════════ 3. Asignar destino ═════════ --}}
        <section class="ui-card" data-testid="step-destination">
            <div class="ui-card-h"><span class="ui-step-n is-{{ $stepState(3) }}">3</span><h2>{{ __('Asignar destino') }}</h2><span class="sp"></span><x-ui.badge :tone="$stepTone[$stepState(3)]">{{ $stepLabel[$stepState(3)] }}</x-ui.badge></div>
            <div class="ui-card-p ui-stack">
                <p class="ui-help">{{ __('Indica a qué programa llega cada formulario (o «contacto general» si no es de un programa) y qué asesor lo atiende.') }}</p>
                @if ($chosenForms->isEmpty())
                    <p class="ui-help">{{ __('Elige primero los formularios en el paso 2.') }}</p>
                @else
                    <ul class="ui-list ui-list--boxed">
                        @foreach ($chosenForms as $form)
                            <li class="ui-list-item ui-list-item--grid" wire:key="dst-{{ $form->id }}">
                                <span class="ui-person-line">
                                    <span class="ui-list-title">{{ $form->name }}</span>
                                    @unless ($form->hasDestination())
                                        <x-ui.badge tone="warning" dot>{{ __('Falta el destino') }}</x-ui.badge>
                                    @endunless
                                </span>
                                <select class="ui-select" aria-label="{{ __('Destino') }}" wire:change="setDestination({{ $form->id }}, $event.target.value)">
                                    <option value="">{{ __('— Elegir destino —') }}</option>
                                    <option value="general" @selected($form->destination === 'general')>{{ __('Contacto general (sin programa)') }}</option>
                                    @foreach ($programs as $p)
                                        <option value="{{ $p->id }}" @selected($form->destination === 'program' && $form->program_id === $p->id)>{{ $p->code }} · {{ $p->name_es }}</option>
                                    @endforeach
                                </select>
                                <select class="ui-select" aria-label="{{ __('Asesor responsable') }}" wire:change="setAdvisor({{ $form->id }}, $event.target.value)">
                                    <option value="">{{ __('— Asesor responsable —') }}</option>
                                    @foreach ($bots as $b)
                                        <option value="{{ $b->id }}" @selected($form->bot_id === $b->id)>{{ $b->assistant_name }}</option>
                                    @endforeach
                                </select>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- ═════════ 4. Comprobar acceso ═════════ --}}
        <section class="ui-card" data-testid="step-access">
            <div class="ui-card-h"><span class="ui-step-n is-{{ $stepState(4) }}">4</span><h2>{{ __('Comprobar acceso') }}</h2><span class="sp"></span><x-ui.badge :tone="$stepTone[$stepState(4)]">{{ $stepLabel[$stepState(4)] }}</x-ui.badge></div>
            @if ($selectedPages->isEmpty())
                <p class="ui-list-empty">{{ __('Elige primero una Página en el paso 2.') }}</p>
            @else
                <ul class="ui-list">
                    @foreach ($selectedPages as $page)
                        @php($result = (array) ($page->access_result ?? []))
                        @php($checks = (array) ($result['checks'] ?? []))
                        <li class="ui-list-item ui-list-item--col" wire:key="ac-{{ $page->id }}">
                            <div class="ui-split">
                                <div class="ui-split-main">
                                    <span class="ui-person-line">
                                        <span class="ui-list-title">{{ $page->name }}</span>
                                        <x-ui.badge :tone="$page->verified() ? 'success' : ($page->access_status === 'failed' ? 'danger' : 'neutral')" dot data-testid="access-status-{{ $page->id }}">{{ [
                                            'verified' => __('Acceso verificado'), 'failed' => __('Acceso con problemas'),
                                            'incomplete' => __('Falta un formulario para comprobar'), 'unchecked' => __('Acceso sin comprobar'),
                                        ][$page->access_status] ?? $page->access_status }}</x-ui.badge>
                                    </span>
                                </div>
                                <div class="ui-actions-row">
                                    <x-ui.button size="sm" variant="primary" icon="shield" wire:click="checkAccess({{ $page->id }})" wire:loading.attr="disabled">{{ __('Comprobar acceso') }}</x-ui.button>
                                    <x-ui.button size="sm" icon="activity" wire:click="receptionTest({{ $page->id }})" wire:confirm="{{ __('Se creará un contacto de prueba de Meta en un formulario elegido, se pasará por el mismo camino que un contacto real hasta el CRM sin guardarlo, y se borrará en Meta al terminar. ¿Continuar?') }}">{{ __('Prueba completa de recepción') }}</x-ui.button>
                                </div>
                            </div>
                            @if ($page->access_checked_at)
                                <ul class="ui-checklist" data-testid="access-checks-{{ $page->id }}">
                                    <li class="{{ $markClass($checks['page'] ?? null) }}"><b>{{ $mark($checks['page'] ?? null) }}</b>{{ __('La conexión llega a la Página') }}</li>
                                    <li class="{{ $markClass($checks['list_forms'] ?? null) }}"><b>{{ $mark($checks['list_forms'] ?? null) }}</b>{{ __('Listar los formularios de la Página') }}
                                        @if (($result['forms'] ?? null) !== null) <span class="ui-cell-sub">({{ trans_choice(':n formulario|:n formularios', $result['forms'], ['n' => $result['forms']]) }})</span> @endif</li>
                                    <li class="{{ $markClass($checks['read_contacts'] ?? null) }}"><b>{{ $mark($checks['read_contacts'] ?? null) }}</b>{{ __('Lectura de contactos autorizada por Meta') }}
                                        @if (($checks['read_contacts'] ?? null) === 'na') <span class="ui-cell-sub">({{ __('sin formularios con los que probar') }})</span> @endif</li>
                                </ul>
                                <p class="ui-help">{{ __('Última comprobación: :date.', ['date' => $page->access_checked_at->format('d/m/Y H:i')]) }}
                                    @if ($page->verified() && ($checks['list_forms'] ?? null) === 'fail') {{ __('Puedes recibir contactos; para traer formularios nuevos hace falta que Meta permita listarlos.') }} @endif</p>
                            @endif
                            {{-- Distinto de la lectura autorizada: demuestra que un contacto entraría de verdad en su destino. --}}
                            @php($rt = (array) ($result['reception_test'] ?? []))
                            <div class="ui-checklist" data-testid="reception-test-{{ $page->id }}">
                                <span class="{{ ['passed' => 'is-ok', 'failed' => 'is-fail'][$rt['status'] ?? ''] ?? '' }}">
                                    @php($rtText = match ($rt['status'] ?? null) {
                                        'passed' => __('superada el :date con «:form».', ['date' => \Illuminate\Support\Carbon::parse($rt['at'])->format('d/m/Y H:i'), 'form' => $rt['form']]),
                                        'failed' => __('fallida el :date: :detail', ['date' => \Illuminate\Support\Carbon::parse($rt['at'])->format('d/m/Y H:i'), 'detail' => $rt['detail']]),
                                        default => __('sin hacer. Crea un contacto de prueba de Meta y lo pasa por el CRM sin guardarlo.'),
                                    })
                                    <b>{{ ['passed' => '✓', 'failed' => '✗'][$rt['status'] ?? ''] ?? '–' }}</b>{{ __('Prueba completa de recepción') }}: {{ $rtText }}
                                </span>
                            </div>
                            @if ($page->access_status === 'incomplete')
                                <p class="ui-callout ui-callout--sm ui-callout--stack ui-tone--warning"><b>{{ __('Lo resuelve tu empresa') }}</b><span>{{ __('La Página aún no tiene formularios: crea uno en Meta y vuelve a «Comprobar acceso».') }}</span></p>
                            @endif
                            <div class="ui-stack" data-testid="page-issues-{{ $page->id }}">
                                @foreach ((array) ($result['issues'] ?? []) as $issue)
                                    <div class="ui-callout ui-callout--sm ui-callout--stack {{ $issue['who'] === 'platform' ? 'ui-tone--deep' : 'ui-tone--warning' }}">
                                        <b>{{ $issue['who'] === 'platform' ? __('Lo resuelve la plataforma del CRM') : __('Lo resuelve tu empresa') }}</b>
                                        <span>{{ Guide::forCompany($issue) }}</span>
                                        @if ($isOperator)
                                            <span class="ui-cell-sub" data-testid="operator-detail">{{ __('Operador') }}: {{ Guide::forOperator($issue) }}
                                                — {{ __('Respuesta de Meta') }}: HTTP {{ $issue['http_status'] ?? '—' }} · #{{ $issue['code'] ?? '—' }} · {{ $issue['meta_message'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ═════════ 5. Activar recepción ═════════ --}}
        <section class="ui-card" data-testid="step-receiving">
            <div class="ui-card-h"><span class="ui-step-n is-{{ $stepState(5) }}">5</span><h2>{{ __('Activar recepción') }}</h2><span class="sp"></span><x-ui.badge :tone="$stepTone[$stepState(5)]">{{ $stepLabel[$stepState(5)] }}</x-ui.badge></div>
            @if ($selectedPages->isEmpty())
                <p class="ui-list-empty">{{ __('Elige primero una Página en el paso 2.') }}</p>
            @else
                <ul class="ui-list">
                    @foreach ($selectedPages as $page)
                        @php($ready = $forms->get($page->id, collect())->where('is_active', true)->filter(fn ($f) => $f->hasDestination())->count())
                        <li class="ui-list-item ui-list-item--col" wire:key="rc-{{ $page->id }}">
                            <div class="ui-split">
                                <div class="ui-split-main">
                                    <span class="ui-person-line">
                                        <span class="ui-list-title">{{ $page->name }}</span>
                                        <x-ui.badge :tone="$page->receiving() ? 'success' : ($page->receiving_enabled ? 'warning' : 'neutral')" dot data-testid="receiving-{{ $page->id }}">{{ $page->receiving() ? __('Recibiendo contactos') : ($page->receiving_enabled ? __('En pausa por un problema de acceso') : __('Recepción en pausa')) }}</x-ui.badge>
                                    </span>
                                    <div class="ui-help">
                                        @if (! $page->verified())
                                            {{ __('Para activarla, completa «Comprobar acceso» (paso 4).') }}
                                        @elseif ($ready === 0)
                                            {{ __('Para activarla, elige un formulario y asígnale un destino (pasos 2 y 3).') }}
                                        @else
                                            {{ trans_choice(':n formulario listo.|:n formularios listos.', $ready, ['n' => $ready]) }}
                                            @if ($page->last_polled_at) {{ __('Última búsqueda: :date.', ['date' => $page->last_polled_at->format('d/m/Y H:i')]) }} @endif
                                            @if ((((array) ($page->access_result ?? []))['reception_test']['status'] ?? null) !== 'passed')
                                                <span data-testid="reception-test-hint-{{ $page->id }}">{{ __('Recomendado antes o después de activar: «Prueba completa de recepción» (paso 4).') }}</span>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                                <div class="ui-actions-row">
                                    @if ($page->receiving_enabled)
                                        <x-ui.button size="sm" icon="search" wire:click="fetchNow({{ $page->id }})" :disabled="! $page->receiving()">{{ __('Buscar contactos ahora') }}</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" icon="power" wire:click="setReceiving({{ $page->id }}, false)">{{ __('Pausar la recepción') }}</x-ui.button>
                                    @else
                                        <x-ui.button size="sm" variant="primary" icon="power" wire:click="setReceiving({{ $page->id }}, true)" :disabled="! $page->verified() || $ready === 0 || $killSwitch">{{ __('Activar la recepción') }}</x-ui.button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ───────── Diagnóstico ───────── --}}
        <details class="ui-card ui-disclosure" data-testid="diagnostics">
            <summary class="ui-card-h"><x-ui.icon name="activity" class="ui-card-ic" /><h2>{{ __('Diagnóstico') }}</h2><span class="sp"></span><x-ui.icon name="chevron-down" class="ui-disclosure-chev" /></summary>
            <div class="ui-card-p">
                <ul class="ui-checklist">
                    <li><b>·</b>{{ __('Conexión') }}: {{ $connection ? ([
                        'active' => __('Conectada'), 'expiring' => __('Renovar pronto'), 'expired' => __('Caducada'), 'invalid' => __('No válida'), 'disconnected' => __('Sin conexión'),
                    ][$connection->effectiveStatus()] ?? '—') : __('Sin conexión') }}
                        @if ($connection?->last_checked_at) — {{ __('revisada el :date', ['date' => $connection->last_checked_at->format('d/m/Y H:i')]) }} @endif</li>
                    @if ($connection && $connection->scopes !== null)
                        @php($granted = collect($neededPermissions)->filter(fn ($p) => in_array($p, $connection->scopes, true)))
                        <li><b>·</b>{{ __('Permisos de Meta concedidos a esta conexión: :n de :t necesarios.', ['n' => $granted->count(), 't' => count($neededPermissions)]) }}
                            @if ($isOperator) <span class="ui-cell-sub">({{ collect($neededPermissions)->map(fn ($p) => (in_array($p, $connection->scopes, true) ? '✓ ' : '✗ ').$p)->implode(' · ') }})</span> @endif</li>
                    @endif
                    <li><b>·</b>{{ __('Recepción de la plataforma') }}: {{ $killSwitch ? __('detenida por el operador') : __('disponible') }}</li>
                    @foreach ($selectedPages as $page)
                        @php($checks = (array) (($page->access_result ?? [])['checks'] ?? []))
                        <li><b>·</b><strong>{{ $page->name }}</strong>:
                            {{ __('Página') }} {{ $mark($checks['page'] ?? null) }} ·
                            {{ __('listar formularios') }} {{ $mark($checks['list_forms'] ?? null) }} ·
                            {{ __('leer contactos') }} {{ $mark($checks['read_contacts'] ?? null) }} ·
                            {{ __('prueba completa') }} {{ ['passed' => '✓', 'failed' => '✗'][((array) ($page->access_result ?? []))['reception_test']['status'] ?? ''] ?? '–' }} ·
                            {{ $page->receiving() ? __('recibiendo') : __('en pausa') }}
                            @if ($page->last_polled_at) · {{ __('última búsqueda :date', ['date' => $page->last_polled_at->format('d/m/Y H:i')]) }} @endif
                            @if ($page->last_error) <div class="ui-help ui-text-danger">{{ $page->last_error }}</div> @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </details>

        <section class="ui-card">
            <div class="ui-card-h"><x-ui.icon name="inbox" class="ui-card-ic" /><h2>{{ __('Últimos contactos recibidos') }}</h2></div>
            @if ($receipts->isEmpty())
                <div class="ui-empty">
                    <div class="ui-empty-ic"><x-ui.icon name="inbox" /></div>
                    {{ __('Todavía no ha llegado ningún contacto por formularios publicitarios.') }}
                </div>
            @else
                <ul class="ui-list">
                    @foreach ($receipts as $r)
                        <li class="ui-list-item ui-list-item--wrap" wire:key="rcp-{{ $r->id }}">
                            <span class="ui-list-main">
                                <span class="ui-person-line">
                                    <x-ui.badge :tone="['processed' => 'success', 'failed' => 'danger'][$r->status] ?? 'neutral'" dot>{{ __(\Modules\Social\Models\MetaLeadReceipt::STATUS_LABELS[$r->status] ?? $r->status) }}</x-ui.badge>
                                    <span class="ui-list-title">{{ $r->attribution['form'] ?? '—' }}</span>
                                </span>
                                @if (! empty($r->attribution['campaign']))
                                    <span class="ui-list-meta">{{ __('Campaña') }}: {{ $r->attribution['campaign'] }}</span>
                                @endif
                                @if ($r->error)
                                    <span class="ui-list-meta ui-text-danger">{{ $r->error }}</span>
                                @endif
                            </span>
                            <span class="ui-list-aside ui-list-meta">{{ $r->created_at?->format('d/m/Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
