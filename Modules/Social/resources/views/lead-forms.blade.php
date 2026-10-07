@php
    use Modules\Social\Support\MetaLeadAccessGuidance as Guide;
    $selectedPages = $pages->where('selected', true);
    $chosenForms = $selectedPages->flatMap(fn ($p) => $forms->get($p->id, collect())->where('is_active', true));
    $stepState = fn (int $n) => $steps['done'][$n] ? 'done' : ($steps['current'] === $n ? 'current' : 'pending');
    $stepLabel = ['done' => __('Hecho'), 'current' => __('Siguiente paso'), 'pending' => __('Pendiente')];
    $mark = fn (?string $s) => ['ok' => '✓', 'fail' => '✗'][$s] ?? '–';
@endphp
<div>
    <x-ui.styles />
    <style>
        .wz-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;list-style:none;margin:0 0 18px;padding:0}
        .wz-steps li{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:11px;background:#fff;border:1px solid var(--line);font-size:12.5px;font-weight:600;color:var(--ink-2,#475467)}
        .wz-steps li span{flex:none;display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#EEF1F6;font-size:12px}
        .wz-steps li.done span{background:#EAF7EF;color:#1E7A45}
        .wz-steps li.current{border-color:var(--mca-blue,#1E5AA8);color:var(--mca-blue,#1E5AA8)}
        .wz-steps li.current span{background:var(--mca-blue,#1E5AA8);color:#fff}
        .wz-card{margin-bottom:16px}
        .wz-card.pending{opacity:.72}
        .wz-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
        .wz-head h3{margin:0;font-size:15px;font-weight:700}
        .wz-chip{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;background:#EEF1F6;color:var(--ink-2,#475467)}
        .wz-chip.done{background:#EAF7EF;color:#1E7A45}
        .wz-chip.current{background:var(--mca-blue-soft,#E8F0FA);color:var(--mca-blue,#1E5AA8)}
        .lf-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:9px 0;border-top:1px solid var(--line)}
        .lf-row:first-of-type{border-top:0}
        .lf-actions{display:flex;gap:8px;flex-wrap:wrap}
        .lf-issue{margin:8px 0 0;padding:9px 11px;border-radius:9px;font-size:13px;background:#FDF0EF}
        .lf-issue.platform{background:#F3F0FB}
        .lf-issue strong{display:block;margin-bottom:2px}
        .lf-tech{font-size:12px;color:var(--muted);margin-top:4px}
        .lf-form{display:grid;grid-template-columns:minmax(170px,1.3fr) minmax(170px,1fr) minmax(150px,1fr);gap:10px;align-items:center;padding:9px 0;border-top:1px solid var(--line)}
        .lf-form select{width:100%;min-width:0;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff}
        .lf-checks{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:4px;font-size:13px}
        .lf-checks b{display:inline-block;width:18px}
        .lf-checks .ok b{color:#1E7A45}.lf-checks .fail b{color:#B3261E}
        .lf-sub{margin:6px 0 0 14px;padding:0;list-style:none}
        @media (max-width:900px){.wz-steps{grid-template-columns:1fr 1fr}.lf-form{grid-template-columns:1fr}}
    </style>

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('social.channels') }}" class="btn btn-ghost btn-sm" aria-label="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ __('Formularios publicitarios') }}</h1>
                    <p class="mca-sub">{{ __('Los contactos que dejan sus datos en los anuncios de Facebook e Instagram entran directamente al CRM, con su programa, su asesor y la campaña de origen.') }}</p>
                </div>
            </div>
        </div>

        <ol class="wz-steps" aria-label="{{ __('Pasos') }}" data-testid="wizard-steps">
            @foreach ([1 => __('Conectar Meta'), 2 => __('Elegir Página y formularios'), 3 => __('Asignar destino'), 4 => __('Comprobar acceso'), 5 => __('Activar recepción')] as $n => $title)
                <li class="{{ $stepState($n) }}" data-step="{{ $n }}" data-state="{{ $stepState($n) }}"><span>{{ $steps['done'][$n] ? '✓' : $n }}</span>{{ $title }}</li>
            @endforeach
        </ol>

        @if ($notice)
            <div class="mca-toast ok fade">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="card card-p" data-testid="lead-forms-error" style="background:#FDF0EF;border-color:#F2C9C5;margin-bottom:14px;font-size:13.5px">{{ $error }}</div>
        @endif
        @if ($killSwitch)
            <div class="card card-p" style="background:#FFF7E6;border-color:#F0DFAE;margin-bottom:14px;font-size:13.5px">
                {{ __('La recepción de formularios está detenida temporalmente por la plataforma. Tu configuración se conserva.') }}
            </div>
        @endif

        {{-- ───────── Gestión de la plataforma (solo operador) ───────── --}}
        @if ($isOperator && $platformIssues->isNotEmpty())
            <div class="card card-p wz-card" data-testid="platform-issues" style="border-color:#D9D0F2">
                <h3 style="margin:0 0 6px;font-size:15px;font-weight:700">{{ __('Gestión de la plataforma') }}</h3>
                <p class="mca-sub" style="margin:0 0 8px">{{ __('Permisos que Meta deniega a la aplicación del CRM (afectan a todas las empresas; no los resuelve cada cliente):') }}</p>
                <ul style="margin:0 0 8px;padding-left:18px;font-size:13.5px">
                    @foreach ($platformIssues as $perm => $count)
                        <li><strong>{{ $perm }}</strong> — {{ trans_choice(':n Página afectada|:n Páginas afectadas', $count, ['n' => $count]) }}</li>
                    @endforeach
                </ul>
                <p style="font-size:13px;margin:0">{{ Guide::forOperator(['area' => 'app', 'who' => 'platform', 'permissions' => $platformIssues->keys()->all()]) }}</p>
            </div>
        @endif

        {{-- ═════════ 1. Conectar Meta ═════════ --}}
        <div class="card card-p wz-card {{ $stepState(1) }}" data-testid="meta-connection">
            <div class="wz-head"><h3>1 · {{ __('Conectar Meta') }}</h3><span class="wz-chip {{ $stepState(1) }}">{{ $stepLabel[$stepState(1)] }}</span></div>
            @if (! $platformReady)
                <p class="mca-help" style="margin:0 0 8px">{{ $isOperator ? __('Configuración de plataforma Meta pendiente.') : __('La integración con Meta no está disponible temporalmente.') }}</p>
            @endif
            @php($state = $connection?->effectiveStatus())
            <div class="lf-row" style="border:0;padding-top:0">
                <div>
                    @if ($connection === null || $state === 'disconnected')
                        <span class="badge badge-off">{{ __('Sin conexión') }}</span>
                        <span class="mca-help" style="margin-left:6px">{{ __('Autoriza a tu empresa en Meta: verás tus Páginas sin copiar identificadores ni tokens.') }}</span>
                    @else
                        <span class="badge {{ $state === 'active' ? 'badge-on' : 'badge-off' }}" data-testid="connection-state">{{ [
                            'active' => __('Conectada'), 'expiring' => __('Renovar pronto'), 'expired' => __('Caducada'), 'invalid' => __('No válida'),
                        ][$state] ?? $state }}</span>
                        <span class="mca-help" style="margin-left:6px">
                            {{ __('Conectada por :who el :date.', ['who' => $connection->connectedBy?->name ?? '—', 'date' => $connection->connected_at?->format('d/m/Y')]) }}
                            {{ $connection->renewBy() ? __('Renovar antes del :date.', ['date' => $connection->renewBy()->format('d/m/Y')]) : __('Sin caducidad.') }}
                        </span>
                    @endif
                </div>
                <div class="lf-actions">
                    @if ($platformReady)
                        @php($fresh = $connection === null || $state === 'disconnected')
                        {{-- La clave cambia con el estado: el botón (wire:ignore) se reconstruye con su texto nuevo. --}}
                        <span wire:key="meta-login-{{ $fresh ? 'connect' : 'reconnect' }}">
                            @include('social::partials.meta-login-button', [
                                'cfg' => $this->browserConfig(), 'action' => 'connect',
                                'idleLabel' => $fresh ? __('Conectar Meta') : __('Reconectar Meta'),
                                'btnClass' => $fresh ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm',
                            ])
                        </span>
                    @endif
                    @if ($connection !== null && $state !== 'disconnected')
                        <button type="button" class="btn btn-soft btn-sm" wire:click="refreshConnection">{{ __('Revisar conexión') }}</button>
                        <button type="button" class="btn btn-soft btn-sm" wire:click="disconnect" wire:confirm="{{ __('¿Desconectar Meta? La recepción de contactos se detendrá; la configuración se conserva.') }}">{{ __('Desconectar') }}</button>
                    @endif
                </div>
            </div>
            @if (in_array($state, ['expiring', 'expired', 'invalid'], true))
                <p class="lf-issue"><strong>{{ __('Lo resuelve tu empresa') }}</strong>{{ __('La autorización de Meta caduca o dejó de ser válida. Pulsa «Reconectar Meta» y vuelve a autorizar; tu configuración se conserva.') }}</p>
            @endif
        </div>

        {{-- ═════════ 2. Elegir Página y formularios ═════════ --}}
        <div class="card card-p wz-card {{ $stepState(2) }}" data-testid="step-pages">
            <div class="wz-head"><h3>2 · {{ __('Elegir Página y formularios') }}</h3><span class="wz-chip {{ $stepState(2) }}">{{ $stepLabel[$stepState(2)] }}</span></div>
            @if (! $steps['done'][1] && $pages->isEmpty())
                <p class="mca-help" style="margin:0">{{ __('Primero conecta Meta: aquí aparecerán tus Páginas.') }}</p>
            @elseif ($pages->isEmpty())
                <p class="mca-help" style="margin:0">{{ __('Tu conexión con Meta no incluye ninguna Página. Pulsa «Reconectar Meta» y selecciona tus Páginas al autorizar.') }}</p>
            @endif
            @foreach ($pages as $page)
                <div data-testid="lead-page-{{ $page->id }}" wire:key="lp-{{ $page->id }}" style="padding:10px 0;border-top:1px solid var(--line)">
                    <div class="lf-row" style="border:0;padding:0">
                        <div>
                            <strong style="font-size:14px">{{ $page->name }}</strong>
                            @if (! $page->available)
                                <span class="badge badge-off">{{ __('No incluida en tu conexión') }}</span>
                            @elseif ($page->selected)
                                <span class="badge badge-on">{{ __('Usada para formularios') }}</span>
                            @elseif (in_array($page->id, $takenElsewhere, true))
                                <span class="badge badge-off">{{ __('La usa otra empresa') }}</span>
                            @endif
                        </div>
                        <div class="lf-actions">
                            @if ($page->selected)
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="sync({{ $page->id }})">{{ __('Actualizar formularios') }}</button>
                                <button type="button" class="btn btn-soft btn-sm" wire:click="selectPage({{ $page->id }}, false)" wire:confirm="{{ __('¿Dejar de usar esta Página? Dejará de recibir contactos.') }}">{{ __('Dejar de usar') }}</button>
                            @elseif (in_array($page->id, $takenElsewhere, true))
                                @if ($page->fullControl() && $transferPageId !== $page->id)
                                    <button type="button" class="btn btn-primary btn-sm" wire:click="askTransfer({{ $page->id }})">{{ __('Transferir a mi empresa') }}</button>
                                @endif
                            @elseif ($page->available)
                                <button type="button" class="btn btn-primary btn-sm" wire:click="selectPage({{ $page->id }}, true)">{{ __('Usar para formularios') }}</button>
                            @endif
                        </div>
                    </div>
                    @if ($transferPageId === $page->id)
                        <div class="lf-issue platform" data-testid="transfer-confirm">
                            <strong>{{ __('Confirmar la transferencia') }}</strong>
                            <ul style="margin:4px 0 8px;padding-left:18px">
                                <li>{{ __('El CRM comprobará ahora con Meta que quien conectó Meta en tu empresa tiene control total de la Página.') }}</li>
                                <li>{{ __('La otra empresa dejará de recibir sus contactos de inmediato; conserva los que ya recibió y verá el aviso.') }}</li>
                                <li>{{ __('En tu empresa la recepción quedará apagada hasta que compruebes el acceso.') }}</li>
                                <li>{{ __('Quedará registrado en la auditoría de las dos empresas.') }}</li>
                            </ul>
                            <label style="display:block;font-size:13px;margin-bottom:4px">{{ __('Escribe el nombre de la Página para confirmar:') }} <strong>{{ $page->name }}</strong></label>
                            <div class="lf-actions">
                                <input type="text" wire:model="transferConfirmation" aria-label="{{ __('Nombre de la Página') }}" style="flex:1;min-width:200px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="confirmTransfer">{{ __('Transferir') }}</button>
                                <button type="button" class="btn btn-soft btn-sm" wire:click="cancelTransfer">{{ __('Cancelar') }}</button>
                            </div>
                        </div>
                    @endif
                    @if (! $page->selected && in_array($page->id, $takenElsewhere, true) && ! $page->fullControl())
                        <p class="mca-help" style="margin:6px 0 0">{{ __('Para transferirla, quien tenga control total de la Página en Meta debe conectar Meta desde tu empresa, o la otra empresa debe dejar de usarla.') }}</p>
                    @endif
                    @if ($page->released_at && ! $page->selected)
                        <p class="lf-issue platform" data-testid="page-released-{{ $page->id }}"><strong>{{ __('La Página pasó a otra empresa') }}</strong>{{ __('El :date otra empresa con control total de la Página en Meta la transfirió. Los contactos que ya recibiste siguen en tu CRM.', ['date' => $page->released_at->format('d/m/Y')]) }}</p>
                    @endif
                    @if ($page->selected)
                        <ul class="lf-sub">
                            @forelse ($forms->get($page->id, collect()) as $form)
                                <li class="lf-row" wire:key="ch-{{ $form->id }}">
                                    <span style="font-size:13.5px">{{ $form->name }}</span>
                                    <button type="button" class="btn btn-sm {{ $form->is_active ? 'btn-primary' : 'btn-soft' }}" wire:click="chooseForm({{ $form->id }}, {{ $form->is_active ? 'false' : 'true' }})">{{ $form->is_active ? __('Elegido') : __('Elegir') }}</button>
                                </li>
                            @empty
                                <li class="mca-help" style="padding-top:6px">{{ __('Sin formularios todavía: pulsa «Actualizar formularios» (o «Comprobar acceso», que también los trae).') }}</li>
                            @endforelse
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ═════════ 3. Asignar destino ═════════ --}}
        <div class="card card-p wz-card {{ $stepState(3) }}" data-testid="step-destination">
            <div class="wz-head"><h3>3 · {{ __('Asignar destino') }}</h3><span class="wz-chip {{ $stepState(3) }}">{{ $stepLabel[$stepState(3)] }}</span></div>
            <p class="mca-help" style="margin:0 0 6px">{{ __('Indica a qué programa llega cada formulario (o «contacto general» si no es de un programa) y qué asesor lo atiende.') }}</p>
            @forelse ($chosenForms as $form)
                <div class="lf-form" wire:key="dst-{{ $form->id }}">
                    <div>
                        <strong style="font-size:13.5px">{{ $form->name }}</strong>
                        @unless ($form->hasDestination())
                            <span class="badge badge-off">{{ __('Falta el destino') }}</span>
                        @endunless
                    </div>
                    <select aria-label="{{ __('Destino') }}" wire:change="setDestination({{ $form->id }}, $event.target.value)">
                        <option value="">{{ __('— Elegir destino —') }}</option>
                        <option value="general" @selected($form->destination === 'general')>{{ __('Contacto general (sin programa)') }}</option>
                        @foreach ($programs as $p)
                            <option value="{{ $p->id }}" @selected($form->destination === 'program' && $form->program_id === $p->id)>{{ $p->code }} · {{ $p->name_es }}</option>
                        @endforeach
                    </select>
                    <select aria-label="{{ __('Asesor responsable') }}" wire:change="setAdvisor({{ $form->id }}, $event.target.value)">
                        <option value="">{{ __('— Asesor responsable —') }}</option>
                        @foreach ($bots as $b)
                            <option value="{{ $b->id }}" @selected($form->bot_id === $b->id)>{{ $b->assistant_name }}</option>
                        @endforeach
                    </select>
                </div>
            @empty
                <p class="mca-help" style="margin:0">{{ __('Elige primero los formularios en el paso 2.') }}</p>
            @endforelse
        </div>

        {{-- ═════════ 4. Comprobar acceso ═════════ --}}
        <div class="card card-p wz-card {{ $stepState(4) }}" data-testid="step-access">
            <div class="wz-head"><h3>4 · {{ __('Comprobar acceso') }}</h3><span class="wz-chip {{ $stepState(4) }}">{{ $stepLabel[$stepState(4)] }}</span></div>
            @forelse ($selectedPages as $page)
                @php($result = (array) ($page->access_result ?? []))
                @php($checks = (array) ($result['checks'] ?? []))
                <div style="padding:10px 0;border-top:1px solid var(--line)" wire:key="ac-{{ $page->id }}">
                    <div class="lf-row" style="border:0;padding:0">
                        <div>
                            <strong style="font-size:14px">{{ $page->name }}</strong>
                            <span class="badge {{ $page->verified() ? 'badge-on' : 'badge-off' }}" data-testid="access-status-{{ $page->id }}">{{ [
                                'verified' => __('Acceso verificado'), 'failed' => __('Acceso con problemas'),
                                'incomplete' => __('Falta un formulario para comprobar'), 'unchecked' => __('Acceso sin comprobar'),
                            ][$page->access_status] ?? $page->access_status }}</span>
                        </div>
                        <div class="lf-actions">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="checkAccess({{ $page->id }})" wire:loading.attr="disabled">{{ __('Comprobar acceso') }}</button>
                            <button type="button" class="btn btn-soft btn-sm" wire:click="receptionTest({{ $page->id }})" wire:confirm="{{ __('Se creará un contacto de prueba de Meta en un formulario elegido, se pasará por el mismo camino que un contacto real hasta el CRM sin guardarlo, y se borrará en Meta al terminar. ¿Continuar?') }}">{{ __('Prueba completa de recepción') }}</button>
                        </div>
                    </div>
                    @if ($page->access_checked_at)
                        <ul class="lf-checks" data-testid="access-checks-{{ $page->id }}">
                            <li class="{{ $checks['page'] ?? '' }}"><b>{{ $mark($checks['page'] ?? null) }}</b>{{ __('La conexión llega a la Página') }}</li>
                            <li class="{{ $checks['list_forms'] ?? '' }}"><b>{{ $mark($checks['list_forms'] ?? null) }}</b>{{ __('Listar los formularios de la Página') }}
                                @if (($result['forms'] ?? null) !== null) <span class="mca-help">({{ trans_choice(':n formulario|:n formularios', $result['forms'], ['n' => $result['forms']]) }})</span> @endif</li>
                            <li class="{{ $checks['read_contacts'] ?? '' }}"><b>{{ $mark($checks['read_contacts'] ?? null) }}</b>{{ __('Lectura de contactos autorizada por Meta') }}
                                @if (($checks['read_contacts'] ?? null) === 'na') <span class="mca-help">({{ __('sin formularios con los que probar') }})</span> @endif</li>
                        </ul>
                        <p class="mca-help" style="margin:6px 0 0">{{ __('Última comprobación: :date.', ['date' => $page->access_checked_at->format('d/m/Y H:i')]) }}
                            @if ($page->verified() && ($checks['list_forms'] ?? null) === 'fail') {{ __('Puedes recibir contactos; para traer formularios nuevos hace falta que Meta permita listarlos.') }} @endif</p>
                    @endif
                    {{-- Distinto de la lectura autorizada: demuestra que un contacto entraría de verdad en su destino. --}}
                    @php($rt = (array) ($result['reception_test'] ?? []))
                    <div class="lf-checks" data-testid="reception-test-{{ $page->id }}" style="margin-top:8px">
                        <span class="{{ ['passed' => 'ok', 'failed' => 'fail'][$rt['status'] ?? ''] ?? '' }}">
                            @php($rtText = match ($rt['status'] ?? null) {
                                'passed' => __('superada el :date con «:form».', ['date' => \Illuminate\Support\Carbon::parse($rt['at'])->format('d/m/Y H:i'), 'form' => $rt['form']]),
                                'failed' => __('fallida el :date: :detail', ['date' => \Illuminate\Support\Carbon::parse($rt['at'])->format('d/m/Y H:i'), 'detail' => $rt['detail']]),
                                default => __('sin hacer. Crea un contacto de prueba de Meta y lo pasa por el CRM sin guardarlo.'),
                            })
                            <b>{{ ['passed' => '✓', 'failed' => '✗'][$rt['status'] ?? ''] ?? '–' }}</b>{{ __('Prueba completa de recepción') }}: {{ $rtText }}
                        </span>
                    </div>
                    @if ($page->access_status === 'incomplete')
                        <p class="lf-issue platform"><strong>{{ __('Lo resuelve tu empresa') }}</strong>{{ __('La Página aún no tiene formularios: crea uno en Meta y vuelve a «Comprobar acceso».') }}</p>
                    @endif
                    <div data-testid="page-issues-{{ $page->id }}">
                        @foreach ((array) ($result['issues'] ?? []) as $issue)
                            <div class="lf-issue {{ $issue['who'] === 'platform' ? 'platform' : '' }}">
                                <strong>{{ $issue['who'] === 'platform' ? __('Lo resuelve la plataforma del CRM') : __('Lo resuelve tu empresa') }}</strong>
                                {{ Guide::forCompany($issue) }}
                                @if ($isOperator)
                                    <div class="lf-tech" data-testid="operator-detail">{{ __('Operador') }}: {{ Guide::forOperator($issue) }}
                                        — {{ __('Respuesta de Meta') }}: HTTP {{ $issue['http_status'] ?? '—' }} · #{{ $issue['code'] ?? '—' }} · {{ $issue['meta_message'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="mca-help" style="margin:0">{{ __('Elige primero una Página en el paso 2.') }}</p>
            @endforelse
        </div>

        {{-- ═════════ 5. Activar recepción ═════════ --}}
        <div class="card card-p wz-card {{ $stepState(5) }}" data-testid="step-receiving">
            <div class="wz-head"><h3>5 · {{ __('Activar recepción') }}</h3><span class="wz-chip {{ $stepState(5) }}">{{ $stepLabel[$stepState(5)] }}</span></div>
            @forelse ($selectedPages as $page)
                @php($ready = $forms->get($page->id, collect())->where('is_active', true)->filter(fn ($f) => $f->hasDestination())->count())
                <div class="lf-row" wire:key="rc-{{ $page->id }}">
                    <div>
                        <strong style="font-size:14px">{{ $page->name }}</strong>
                        <span class="badge {{ $page->receiving() ? 'badge-on' : 'badge-off' }}" data-testid="receiving-{{ $page->id }}">{{ $page->receiving() ? __('Recibiendo contactos') : ($page->receiving_enabled ? __('En pausa por un problema de acceso') : __('Recepción en pausa')) }}</span>
                        <div class="mca-help">
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
                    <div class="lf-actions">
                        @if ($page->receiving_enabled)
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="fetchNow({{ $page->id }})" @disabled(! $page->receiving())>{{ __('Buscar contactos ahora') }}</button>
                            <button type="button" class="btn btn-soft btn-sm" wire:click="setReceiving({{ $page->id }}, false)">{{ __('Pausar la recepción') }}</button>
                        @else
                            <button type="button" class="btn btn-primary btn-sm" wire:click="setReceiving({{ $page->id }}, true)" @disabled(! $page->verified() || $ready === 0 || $killSwitch)>{{ __('Activar la recepción') }}</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="mca-help" style="margin:0">{{ __('Elige primero una Página en el paso 2.') }}</p>
            @endforelse
        </div>

        {{-- ───────── Diagnóstico ───────── --}}
        <details class="card card-p wz-card" data-testid="diagnostics">
            <summary style="cursor:pointer;font-size:15px;font-weight:700">{{ __('Diagnóstico') }}</summary>
            <ul class="lf-checks" style="margin-top:10px">
                <li><b>·</b>{{ __('Conexión') }}: {{ $connection ? ([
                    'active' => __('Conectada'), 'expiring' => __('Renovar pronto'), 'expired' => __('Caducada'), 'invalid' => __('No válida'), 'disconnected' => __('Sin conexión'),
                ][$connection->effectiveStatus()] ?? '—') : __('Sin conexión') }}
                    @if ($connection?->last_checked_at) — {{ __('revisada el :date', ['date' => $connection->last_checked_at->format('d/m/Y H:i')]) }} @endif</li>
                @if ($connection && $connection->scopes !== null)
                    @php($granted = collect($neededPermissions)->filter(fn ($p) => in_array($p, $connection->scopes, true)))
                    <li><b>·</b>{{ __('Permisos de Meta concedidos a esta conexión: :n de :t necesarios.', ['n' => $granted->count(), 't' => count($neededPermissions)]) }}
                        @if ($isOperator) <span class="lf-tech">({{ collect($neededPermissions)->map(fn ($p) => (in_array($p, $connection->scopes, true) ? '✓ ' : '✗ ').$p)->implode(' · ') }})</span> @endif</li>
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
                        @if ($page->last_error) <div class="mca-help" style="color:#8A1C1C">{{ $page->last_error }}</div> @endif
                    </li>
                @endforeach
            </ul>
        </details>

        <div class="card card-p wz-card">
            <h3 style="margin:0 0 10px;font-size:15px;font-weight:700">{{ __('Últimos contactos recibidos') }}</h3>
            @forelse ($receipts as $r)
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line);font-size:13px" wire:key="rcp-{{ $r->id }}">
                    <span class="badge {{ $r->status === 'processed' ? 'badge-on' : 'badge-off' }}">{{ __(\Modules\Social\Models\MetaLeadReceipt::STATUS_LABELS[$r->status] ?? $r->status) }}</span>
                    <span>{{ $r->attribution['form'] ?? '—' }}</span>
                    @if (! empty($r->attribution['campaign']))
                        <span class="mca-help">{{ __('Campaña') }}: {{ $r->attribution['campaign'] }}</span>
                    @endif
                    @if ($r->error)
                        <span class="mca-help" style="color:#8A1C1C">{{ $r->error }}</span>
                    @endif
                    <span class="mca-help" style="margin-left:auto">{{ $r->created_at?->format('d/m/Y H:i') }}</span>
                </div>
            @empty
                <p class="mca-sub" style="margin:0">{{ __('Todavía no ha llegado ningún contacto por formularios publicitarios.') }}</p>
            @endforelse
        </div>
    </div>
</div>
