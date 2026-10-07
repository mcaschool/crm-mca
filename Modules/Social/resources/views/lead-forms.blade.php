<div>
    <x-ui.styles />
    <style>
        .lf-steps{display:flex;flex-wrap:wrap;gap:8px 18px;list-style:none;margin:0 0 16px;padding:0;font-size:13px;color:var(--ink-2,#475467)}
        .lf-steps li{display:flex;align-items:center;gap:7px}
        .lf-steps span{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--mca-blue-soft,#E8F0FA);color:var(--mca-blue,#1E5AA8);font-weight:700;font-size:12px}
        .lf-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
        .lf-actions{display:flex;gap:8px;flex-wrap:wrap}
        .lf-issue{margin:8px 0 0;padding:9px 11px;border-radius:9px;font-size:13px;background:#FDF0EF}
        .lf-issue.platform{background:#F3F0FB}
        .lf-issue strong{display:block;margin-bottom:2px}
        .lf-tech{font-size:12px;color:var(--muted);margin-top:4px}
        .lf-form{display:grid;grid-template-columns:minmax(170px,1.4fr) minmax(170px,1fr) minmax(150px,1fr) auto;gap:10px;align-items:center;padding:9px 0;border-top:1px solid var(--line)}
        .lf-form select{width:100%;min-width:0;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff}
        @media (max-width:760px){.lf-form{grid-template-columns:1fr}}
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

        <ol class="lf-steps" aria-label="{{ __('Pasos') }}">
            <li><span>1</span>{{ __('Conectar Meta') }}</li>
            <li><span>2</span>{{ __('Elegir Páginas y formularios') }}</li>
            <li><span>3</span>{{ __('Asignar programa') }}</li>
            <li><span>4</span>{{ __('Comprobar acceso') }}</li>
            <li><span>5</span>{{ __('Activar la recepción') }}</li>
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
            <div class="card card-p" data-testid="platform-issues" style="margin-bottom:18px;border-color:#D9D0F2">
                <h3 style="margin:0 0 6px;font-size:15px;font-weight:700">{{ __('Gestión de la plataforma') }}</h3>
                <p class="mca-sub" style="margin:0 0 8px">{{ __('Permisos que Meta deniega a la aplicación del CRM (afectan a todas las empresas; no los resuelve cada cliente):') }}</p>
                <ul style="margin:0 0 8px;padding-left:18px;font-size:13.5px">
                    @foreach ($platformIssues as $perm => $count)
                        <li><strong>{{ $perm }}</strong> — {{ trans_choice(':n Página afectada|:n Páginas afectadas', $count, ['n' => $count]) }}</li>
                    @endforeach
                </ul>
                <p style="font-size:13px;margin:0">{{ \Modules\Social\Support\MetaLeadAccessGuidance::forOperator(['area' => 'app', 'who' => 'platform', 'permissions' => $platformIssues->keys()->all()]) }}</p>
            </div>
        @endif

        {{-- ───────── 1. Conexión con Meta ───────── --}}
        <div class="card card-p" data-testid="meta-connection" style="margin-bottom:18px">
            <h3 style="margin:0 0 10px;font-size:15px;font-weight:700">{{ __('Conexión con Meta') }}</h3>
            @if (! $platformReady)
                <p class="mca-help" style="margin:0 0 8px">{{ $isOperator ? __('Configuración de plataforma Meta pendiente.') : __('La integración con Meta no está disponible temporalmente.') }}</p>
            @endif
            @if ($connection === null || $connection->effectiveStatus() === 'disconnected')
                <div class="lf-row">
                    <span><span class="badge badge-off">{{ __('Sin conexión') }}</span>
                        <span class="mca-help" style="margin-left:6px">{{ __('Autoriza a tu empresa en Meta para ver tus Páginas y sus formularios.') }}</span></span>
                    @if ($platformReady)
                        <a href="{{ route('social.meta') }}" class="btn btn-primary btn-sm">{{ __('Conectar Meta') }}</a>
                    @endif
                </div>
            @else
                @php($state = $connection->effectiveStatus())
                <div class="lf-row">
                    <div>
                        <span class="badge {{ $state === 'active' ? 'badge-on' : 'badge-off' }}" data-testid="connection-state">{{ [
                            'active' => __('Conectada'), 'expiring' => __('Renovar pronto'), 'expired' => __('Caducada'), 'invalid' => __('No válida'),
                        ][$state] ?? $state }}</span>
                        <span class="mca-help" style="margin-left:6px">
                            {{ __('Conectada por :who el :date.', ['who' => $connection->connectedBy?->name ?? '—', 'date' => $connection->connected_at?->format('d/m/Y')]) }}
                            {{ $connection->renewBy() ? __('Renovar antes del :date.', ['date' => $connection->renewBy()->format('d/m/Y')]) : __('Sin caducidad.') }}
                        </span>
                    </div>
                    <div class="lf-actions">
                        @if ($platformReady)
                            <a href="{{ route('social.meta') }}" class="btn btn-ghost btn-sm">{{ __('Reconectar Meta') }}</a>
                        @endif
                        <button type="button" class="btn btn-soft btn-sm" wire:click="refreshConnection">{{ __('Revisar conexión') }}</button>
                        <button type="button" class="btn btn-soft btn-sm" wire:click="disconnect" wire:confirm="{{ __('¿Desconectar Meta? La recepción de contactos se detendrá; la configuración se conserva.') }}">{{ __('Desconectar') }}</button>
                    </div>
                </div>
                @if (in_array($state, ['expiring', 'expired', 'invalid'], true))
                    <p class="lf-issue" style="margin-top:10px"><strong>{{ __('Lo resuelve tu empresa') }}</strong>{{ __('La autorización de Meta caduca o dejó de ser válida. Pulsa «Reconectar Meta» y vuelve a autorizar; tu configuración se conserva.') }}</p>
                @endif
            @endif
        </div>

        {{-- ───────── 2-5. Páginas ───────── --}}
        @foreach ($pages as $page)
            @php($result = (array) ($page->access_result ?? []))
            <div class="card card-p" style="margin-bottom:18px" data-testid="lead-page-{{ $page->id }}" wire:key="lp-{{ $page->id }}">
                <div class="lf-row">
                    <div>
                        <strong style="font-size:14.5px">{{ $page->name }}</strong>
                        @if (! $page->available)
                            <span class="badge badge-off">{{ __('No incluida en tu conexión') }}</span>
                        @elseif ($page->selected)
                            <span class="badge badge-on">{{ __('Usada para formularios') }}</span>
                        @endif
                        @if ($page->selected)
                            <span class="badge {{ $page->verified() ? 'badge-on' : 'badge-off' }}" data-testid="access-status-{{ $page->id }}">{{ [
                                'verified' => __('Acceso verificado'), 'failed' => __('Acceso con problemas'),
                                'incomplete' => __('Falta un formulario para comprobar'), 'unchecked' => __('Acceso sin comprobar'),
                            ][$page->access_status] ?? $page->access_status }}</span>
                            <span class="badge {{ $page->receiving() ? 'badge-on' : 'badge-off' }}" data-testid="receiving-{{ $page->id }}">{{ $page->receiving() ? __('Recibiendo contactos') : __('Recepción apagada') }}</span>
                        @endif
                    </div>
                    <button type="button" class="btn btn-sm {{ $page->selected ? 'btn-soft' : 'btn-primary' }}" wire:click="selectPage({{ $page->id }}, {{ $page->selected ? 'false' : 'true' }})" @disabled(! $page->available && ! $page->selected)>
                        {{ $page->selected ? __('Dejar de usar') : __('Usar para formularios') }}
                    </button>
                </div>

                @if ($page->selected)
                    <div class="lf-actions" style="margin-top:12px">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="sync({{ $page->id }})">{{ __('Actualizar formularios') }}</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="checkAccess({{ $page->id }})" wire:loading.attr="disabled">{{ __('Comprobar acceso') }}</button>
                        <button type="button" class="btn btn-soft btn-sm" wire:click="testLead({{ $page->id }})" wire:confirm="{{ __('Se creará un contacto de prueba de Meta en el primer formulario de la Página, se leerá y se borrará al terminar. ¿Continuar?') }}">{{ __('Probar con un contacto de prueba') }}</button>
                        @if ($page->receiving_enabled)
                            <button type="button" class="btn btn-soft btn-sm" wire:click="setReceiving({{ $page->id }}, false)">{{ __('Detener la recepción') }}</button>
                        @else
                            <button type="button" class="btn btn-primary btn-sm" wire:click="setReceiving({{ $page->id }}, true)" @disabled(! $page->verified() || $killSwitch)>{{ __('Activar la recepción') }}</button>
                        @endif
                    </div>

                    @if ($page->access_checked_at)
                        <p class="mca-help" style="margin:8px 0 0">{{ __('Última comprobación: :date.', ['date' => $page->access_checked_at->format('d/m/Y H:i')]) }}
                            @if (($result['forms'] ?? null) !== null) {{ trans_choice(':n formulario en la Página.|:n formularios en la Página.', $result['forms'], ['n' => $result['forms']]) }} @endif
                            @if ($result['leads_readable'] ?? false) {{ __('El CRM puede leer sus contactos.') }} @endif
                        </p>
                    @endif
                    @if ($page->access_status === 'incomplete')
                        <p class="lf-issue platform"><strong>{{ __('Lo resuelve tu empresa') }}</strong>{{ __('La Página aún no tiene formularios: crea uno en Meta y vuelve a «Comprobar acceso».') }}</p>
                    @endif
                    <div data-testid="page-issues-{{ $page->id }}">
                        @foreach ((array) ($result['issues'] ?? []) as $issue)
                            <div class="lf-issue {{ $issue['who'] === 'platform' ? 'platform' : '' }}">
                                <strong>{{ $issue['who'] === 'platform' ? __('Lo resuelve la plataforma del CRM') : __('Lo resuelve tu empresa') }}</strong>
                                {{ \Modules\Social\Support\MetaLeadAccessGuidance::forCompany($issue) }}
                                @if ($isOperator)
                                    <div class="lf-tech" data-testid="operator-detail">{{ __('Operador') }}: {{ \Modules\Social\Support\MetaLeadAccessGuidance::forOperator($issue) }}
                                        — {{ __('Respuesta de Meta') }}: HTTP {{ $issue['http_status'] ?? '—' }} · #{{ $issue['code'] ?? '—' }} · {{ $issue['meta_message'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($page->last_error && $page->access_status !== 'failed')
                        <p class="lf-issue">{{ $page->last_error }}</p>
                    @endif

                    <div style="margin-top:12px">
                        <h4 style="margin:0;font-size:13.5px;font-weight:700">{{ __('Formularios') }}</h4>
                        @forelse ($forms->get($page->id, collect()) as $form)
                            <div class="lf-form" wire:key="fm-{{ $form->id }}">
                                <strong style="font-size:13.5px">{{ $form->name }}</strong>
                                <select aria-label="{{ __('Programa') }}" wire:change="setProgram({{ $form->id }}, $event.target.value)">
                                    <option value="">{{ __('— Programa —') }}</option>
                                    @foreach ($programs as $p)
                                        <option value="{{ $p->id }}" @selected($form->program_id === $p->id)>{{ $p->code }} · {{ $p->name_es }}</option>
                                    @endforeach
                                </select>
                                <select aria-label="{{ __('Asesor responsable') }}" wire:change="setAdvisor({{ $form->id }}, $event.target.value)">
                                    <option value="">{{ __('— Asesor responsable —') }}</option>
                                    @foreach ($bots as $b)
                                        <option value="{{ $b->id }}" @selected($form->bot_id === $b->id)>{{ $b->assistant_name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-sm {{ $form->is_active ? 'btn-primary' : 'btn-soft' }}" wire:click="toggle({{ $form->id }})">{{ $form->is_active ? __('Activado') : __('Desactivado') }}</button>
                            </div>
                        @empty
                            <p class="mca-help" style="margin:6px 0 0">{{ __('Sin formularios todavía: pulsa «Actualizar formularios».') }}</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @endforeach

        @if ($pages->isEmpty() && $connection !== null && $connection->effectiveStatus() !== 'disconnected')
            <div class="card card-p" style="margin-bottom:18px"><p class="mca-sub" style="margin:0">{{ __('Tu conexión con Meta no incluye ninguna Página. Pulsa «Reconectar Meta» y selecciona tus Páginas al autorizar.') }}</p></div>
        @endif

        <div class="card card-p">
            <h3 style="margin:0 0 10px;font-size:15px;font-weight:700">{{ __('Últimos contactos recibidos') }}</h3>
            @forelse ($receipts as $r)
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line);font-size:13px" wire:key="rc-{{ $r->id }}">
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
