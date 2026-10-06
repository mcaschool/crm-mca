<div>
    <x-ui.styles />
    <style>
        .lf-steps{display:flex;flex-wrap:wrap;gap:8px 18px;list-style:none;margin:0 0 16px;padding:0;font-size:13px;color:var(--ink-2,#475467)}
        .lf-steps li{display:flex;align-items:center;gap:7px}
        .lf-steps span{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--mca-blue-soft,#E8F0FA);color:var(--mca-blue,#1E5AA8);font-weight:700;font-size:12px}
        .lf-check{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:6px}
        .lf-check li{display:grid;grid-template-columns:minmax(150px,240px) 1fr;gap:4px 12px;font-size:13px;padding:7px 10px;border-radius:9px;background:#F6F7F9}
        .lf-check li.ok{background:#EAF7EF}
        .lf-check li.fail{background:#FDF0EF}
        @media (max-width:640px){.lf-check li{grid-template-columns:1fr}}
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

        @unless ($enabled)
            <div class="card card-p" data-testid="lead-forms-pending" style="display:flex;gap:10px;align-items:flex-start;background:var(--mca-warn-soft,#FFF7E6);border-color:#F0DFAE;margin-bottom:18px">
                <x-ui.icon name="clock" class="ic" style="width:18px;height:18px;flex:none;margin-top:2px" />
                <div style="font-size:13.5px">
                    <strong>{{ __('Pendiente de aprobación de Meta.') }}</strong>
                    {{ __('Cuando Meta autorice el acceso a los formularios podrás actualizarlos y activarlos aquí. Mientras tanto no entra ningún contacto por esta vía; puedes dejar preparados el programa y el asesor de cada formulario.') }}
                </div>
            </div>
        @endunless

        @if ($notice)
            <div class="mca-toast ok fade">{{ $notice }}</div>
        @endif

        <ol class="lf-steps" aria-label="{{ __('Pasos') }}">
            <li><span>1</span>{{ __('Conectar Meta') }}</li>
            <li><span>2</span>{{ __('Elegir formularios') }}</li>
            <li><span>3</span>{{ __('Asignar programa') }}</li>
        </ol>

        <div class="card card-p" style="margin-bottom:18px">
            <h3 style="margin:0 0 10px;font-size:15px;font-weight:700">{{ __('Páginas de Facebook') }}</h3>
            @forelse ($pages as $page)
                <div style="padding:8px 0;border-top:1px solid var(--line)" wire:key="pg-{{ $page->id }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                        <span>{{ $page->display_name }}</span>
                        <span style="display:flex;gap:8px">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="checkAccess({{ $page->id }})" wire:loading.attr="disabled">{{ __('Comprobar acceso') }}</button>
                            <button type="button" class="btn btn-soft btn-sm" wire:click="sync({{ $page->id }})" @disabled(! $enabled)>{{ __('Actualizar formularios') }}</button>
                        </span>
                    </div>
                    @if (isset($access[$page->id]))
                        @php
                            $a = $access[$page->id];
                            $rows = [
                                'page' => [__('Página autorizada'), __('La conexión actual llega a la Página.'), __('La Página no está autorizada para esta conexión o la conexión caducó: vuelve a conectar Meta.')],
                                'business' => [__('Acceso del negocio a los contactos'), __('El negocio permite leer los contactos de sus formularios.'), __('El negocio no permite a esta conexión leer los contactos (configuración de acceso a clientes potenciales del negocio).')],
                                'app' => [__('Permiso de la aplicación'), __('La aplicación puede leer los contactos de los formularios.'), __('La aplicación aún no tiene permiso para leer los contactos de los formularios.')],
                            ];
                        @endphp
                        <ul class="lf-check" data-testid="lead-access-{{ $page->id }}">
                            @foreach ($rows as $key => [$label, $ok, $fail])
                                <li class="{{ $a[$key] }}">
                                    <strong>{{ $label }}</strong>
                                    <span>{{ $a[$key] === 'ok' ? $ok : ($a[$key] === 'fail' ? $fail : __('Sin datos suficientes para comprobarlo.')) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($a['forms'] !== null)
                            <p class="mca-help" style="margin:6px 0 0">{{ trans_choice(':n formulario encontrado en la Página.|:n formularios encontrados en la Página.', $a['forms'], ['n' => $a['forms']]) }}</p>
                        @endif
                    @endif
                </div>
            @empty
                <p class="mca-sub" style="margin:0">{{ __('No hay ninguna Página de Facebook conectada. Conéctala en «Canales sociales».') }}</p>
            @endforelse
        </div>

        <div class="card card-p" style="margin-bottom:18px">
            <h3 style="margin:0 0 10px;font-size:15px;font-weight:700">{{ __('Formularios') }}</h3>
            @forelse ($forms as $form)
                <div style="display:grid;grid-template-columns:minmax(180px,1.4fr) minmax(180px,1fr) minmax(150px,1fr) auto;gap:10px;align-items:center;padding:10px 0;border-top:1px solid var(--line)" wire:key="fm-{{ $form->id }}">
                    <div>
                        <strong style="font-size:13.5px">{{ $form->name }}</strong>
                        <div class="mca-help">{{ $form->channel?->display_name }}</div>
                    </div>
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
                <p class="mca-sub" style="margin:0">{{ __('Aún no hay formularios. Aparecerán al actualizar los formularios de una Página.') }}</p>
            @endforelse
        </div>

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
