<div>
    <x-ui.styles />
    @push('styles')
        <style>
            .mcp-tabs{display:flex;gap:6px;margin:0 0 18px}
            .mcp-tab{border:1px solid #E6EAF0;background:#fff;border-radius:9px;padding:7px 14px;font-size:13px;font-weight:600;color:#6B7686;cursor:pointer;font-family:inherit}
            .mcp-tab.on{background:#1E5AA8;border-color:#1E5AA8;color:#fff}
            .mcp-ind{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:14px}
            .mcp-ind__item{border:1px solid #E6EAF0;border-radius:10px;padding:11px 13px;background:#fff}
            .mcp-ind__k{font-size:11.5px;color:#6B7686;font-weight:600}
            .mcp-ind__v{font-size:14px;font-weight:700;color:#1F2A37;margin-top:3px;word-break:break-all}
            .mcp-ok{color:#1F7A3D}.mcp-bad{color:#8A1C1C}
            .mcp-endpoint{font-family:ui-monospace,monospace;font-size:12.5px;background:#F4F6F9;border-radius:8px;padding:6px 10px;word-break:break-all}
            .mcp-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
            .mcp-table{width:100%;border-collapse:collapse;font-size:13px}
            .mcp-table th{text-align:left;font-size:11.5px;color:#6B7686;font-weight:600;padding:8px 10px;border-bottom:1px solid #E6EAF0}
            .mcp-table td{padding:9px 10px;border-bottom:1px solid #F0F2F5;vertical-align:middle}
            .mcp-overlay{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:60;display:flex;align-items:center;justify-content:center;padding:24px}
            .mcp-modal{background:#fff;border-radius:14px;width:min(560px,100%);max-height:90vh;overflow:auto;box-shadow:0 18px 50px rgba(16,24,40,.25)}
            .mcp-modal__head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid #E6EAF0}
            .mcp-modal__head h2{margin:0;font-size:15px;font-weight:700}
            .mcp-modal__body{padding:16px 18px}
            .mcp-modal__foot{display:flex;justify-content:flex-end;gap:10px;padding:13px 18px;border-top:1px solid #E6EAF0}
            .mcp-steps{display:flex;gap:6px;margin-bottom:14px}
            .mcp-steps span{flex:1;height:4px;border-radius:2px;background:#E6EAF0}
            .mcp-steps span.on{background:#1E5AA8}
            .mcp-token{font-family:ui-monospace,monospace;font-size:13px;background:#0F1B2D;color:#DDE7F5;border-radius:9px;padding:12px 14px;word-break:break-all;user-select:all}
            .mcp-warn{background:#FFF7E6;border:1px solid #F0DFAE;color:#7A5B12;border-radius:9px;padding:10px 13px;font-size:12.5px;margin-top:10px}
            .mcp-test{display:flex;flex-direction:column;gap:6px;margin-top:12px;font-size:13.5px}
            .mcp-tech{margin-top:20px;font-size:12.5px;color:#6B7686}
            .mcp-tech summary{cursor:pointer;font-weight:600}
            .mcp-radio{display:flex;flex-direction:column;gap:8px}
            .mcp-radio label{display:flex;gap:9px;align-items:flex-start;border:1px solid #E6EAF0;border-radius:10px;padding:11px 13px;cursor:pointer}
        </style>
    @endpush

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">ChatGPT / MCP</h1>
                <p class="mca-sub">{{ __('Conecta ChatGPT (u otro asistente MCP) a MCA CRM para consultar y operar el sistema de forma segura, sin usar terminal ni editar archivos.') }}</p>
            </div>
        </div>

        @if (session('mcpStatus'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('mcpStatus') }}</div>
        @endif

        <div class="mcp-tabs">
            <button type="button" class="mcp-tab {{ $tab === 'dashboard' ? 'on' : '' }}" wire:click="setTab('dashboard')">{{ __('Estado') }}</button>
            <button type="button" class="mcp-tab {{ $tab === 'clients' ? 'on' : '' }}" wire:click="setTab('clients')">{{ __('Accesos') }}</button>
            <button type="button" class="mcp-tab {{ $tab === 'activity' ? 'on' : '' }}" wire:click="setTab('activity')">{{ __('Actividad') }}</button>
        </div>

        {{-- ============================ ESTADO ============================ --}}
        @if ($tab === 'dashboard')
            <div class="card card-p">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <h3 style="margin:0;font-size:16px;font-weight:700">{{ __('Servidor') }}: MCA CRM MCP</h3>
                    <span class="badge {{ $diagnostics['active_clients'] > 0 ? 'badge-on' : 'badge-off' }}">
                        {{ $diagnostics['active_clients'] > 0 ? __('Activo') : __('Inactivo') }}
                    </span>
                </div>
                <div style="margin-top:10px">
                    <div class="mcp-ind__k">{{ __('Endpoint') }}</div>
                    <div class="mcp-endpoint">{{ $diagnostics['endpoint'] }}</div>
                </div>

                <div class="mcp-ind">
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">{{ __('Servidor MCP') }}</div>
                        <div class="mcp-ind__v {{ $diagnostics['db'] ? 'mcp-ok' : 'mcp-bad' }}">{{ $diagnostics['db'] ? __('Operativo') : __('Con problemas') }}</div>
                    </div>
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">HTTPS</div>
                        <div class="mcp-ind__v {{ $diagnostics['https'] ? 'mcp-ok' : 'mcp-bad' }}">{{ $diagnostics['https'] ? __('Válido') : __('No (usa HTTPS en producción)') }}</div>
                    </div>
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">{{ __('Clientes autorizados') }}</div>
                        <div class="mcp-ind__v">{{ $diagnostics['active_clients'] }} / {{ $diagnostics['total_clients'] }}</div>
                    </div>
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">{{ __('Herramientas') }}</div>
                        <div class="mcp-ind__v">{{ $diagnostics['tools'] }}</div>
                    </div>
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">{{ __('Última actividad') }}</div>
                        <div class="mcp-ind__v">{{ $diagnostics['last_activity']?->diffForHumans() ?? '—' }}</div>
                    </div>
                    <div class="mcp-ind__item">
                        <div class="mcp-ind__k">{{ __('Último error') }}</div>
                        <div class="mcp-ind__v {{ $diagnostics['last_error'] ? 'mcp-bad' : '' }}">
                            {{ $diagnostics['last_error'] ? \Illuminate\Support\Str::limit($diagnostics['last_error'], 40) : __('Ninguno') }}
                        </div>
                    </div>
                </div>

                <div class="mcp-actions">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openWizard">
                        <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Crear conexión') }}
                    </button>
                    <button type="button" class="btn btn-sm" wire:click="testServer" wire:loading.attr="disabled" wire:target="testServer">
                        <span wire:loading.remove wire:target="testServer">{{ __('Probar servidor MCP') }}</span>
                        <span wire:loading wire:target="testServer">{{ __('Probando…') }}</span>
                    </button>
                    <button type="button" class="btn btn-soft btn-sm" wire:click="setTab('clients')">{{ __('Administrar accesos') }}</button>
                    <button type="button" class="btn btn-soft btn-sm" wire:click="setTab('activity')">{{ __('Ver actividad') }}</button>
                </div>

                @if ($testResult !== null)
                    <div class="mcp-test">
                        @if ($testResult['ok'])
                            <div class="mcp-ok">✓ {{ __('MCP operativo') }}</div>
                            <div class="mcp-ok">✓ {{ __('Autenticación funcionando') }}</div>
                            <div class="mcp-ok">✓ {{ __(':n herramientas disponibles', ['n' => $testResult['tools']]) }}</div>
                        @else
                            <div class="mcp-bad">✕ {{ __('No se pudo verificar el servidor') }}</div>
                            <div class="mcp-bad">{{ __('Motivo') }}: {{ $testResult['reason'] }}</div>
                        @endif
                    </div>
                @endif

                <details class="mcp-tech">
                    <summary>{{ __('Información técnica') }}</summary>
                    <div style="margin-top:8px;line-height:1.7">
                        <div>{{ __('Transporte') }}: Streamable HTTP (JSON-RPC 2.0), POST únicamente.</div>
                        <div>{{ __('Autenticación') }}: header <code>Authorization: Bearer &lt;clave&gt;</code>.</div>
                        <div>{{ __('Base de datos') }}: {{ $diagnostics['db'] ? 'ok' : 'error' }} · {{ __('Auditoría') }}: mcp_audit_logs.</div>
                        <div>{{ __('Alta de credenciales por consola') }}: <code>php artisan mcp:client</code> (equivalente a esta pantalla).</div>
                    </div>
                </details>
            </div>

        {{-- ============================ ACCESOS ============================ --}}
        @elseif ($tab === 'clients')
            <div class="card card-p">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px">
                    <h3 style="margin:0;font-size:15px;font-weight:700">{{ __('Conexiones autorizadas') }}</h3>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openWizard">
                        <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Crear conexión') }}
                    </button>
                </div>

                <table class="mcp-table">
                    <thead><tr>
                        <th>{{ __('Nombre') }}</th><th>{{ __('Alcance') }}</th><th>{{ __('Estado') }}</th>
                        <th>{{ __('Creado') }}</th><th>{{ __('Último uso') }}</th><th>{{ __('Acciones') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($clientsRows as $c)
                        <tr wire:key="cli-{{ $c['id'] }}">
                            <td style="font-weight:600">{{ $c['name'] }}</td>
                            <td>{{ $c['scope'] }}</td>
                            <td><span class="badge {{ $c['active'] ? 'badge-on' : 'badge-off' }}">{{ $c['active'] ? __('Activo') : __('Revocado') }}</span></td>
                            <td>{{ $c['created']?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $c['last_used']?->diffForHumans() ?? __('nunca') }}</td>
                            <td>
                                @if ($c['active'])
                                    <button type="button" class="btn btn-soft btn-sm" wire:click="askRotate({{ $c['id'] }})">{{ __('Rotar clave') }}</button>
                                    <button type="button" class="btn btn-soft btn-sm" wire:click="askRevoke({{ $c['id'] }})">{{ __('Revocar') }}</button>
                                @else
                                    <span style="color:#6B7686;font-size:12px">{{ __('sin acceso') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:#6B7686;padding:22px">{{ __('Aún no hay conexiones. Crea la primera con «Crear conexión».') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        {{-- ============================ ACTIVIDAD ============================ --}}
        @elseif ($tab === 'activity')
            <div class="card card-p">
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                    <select wire:model.live="fClient" style="max-width:200px">
                        <option value="">{{ __('Todos los clientes') }}</option>
                        @foreach ($clientsForFilter as $cf)
                            <option value="{{ $cf->id }}">{{ $cf->name }}</option>
                        @endforeach
                    </select>
                    <input type="search" wire:model.live.debounce.300ms="fTool" placeholder="{{ __('Herramienta…') }}" style="max-width:180px">
                    <select wire:model.live="fStatus" style="max-width:150px">
                        <option value="">{{ __('Todos') }}</option>
                        <option value="ok">{{ __('Éxito') }}</option>
                        <option value="error">{{ __('Error') }}</option>
                    </select>
                </div>

                <table class="mcp-table">
                    <thead><tr>
                        <th>{{ __('Fecha') }}</th><th>{{ __('Herramienta') }}</th><th>{{ __('Acción') }}</th>
                        <th>{{ __('Recurso') }}</th><th>{{ __('Resultado') }}</th><th>{{ __('Duración') }}</th><th>Correlation</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($activity as $a)
                        <tr wire:key="act-{{ $a->id }}">
                            <td>{{ $a->created_at?->format('d/m H:i:s') }}</td>
                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $a->tool }}</td>
                            <td>{{ $a->action ?: '—' }}</td>
                            <td style="font-size:12px">{{ $a->resource ?: '—' }}</td>
                            <td><span class="{{ $a->status === 'ok' ? 'mcp-ok' : 'mcp-bad' }}">{{ $a->status === 'ok' ? __('Éxito') : __('Error') }}</span></td>
                            <td>{{ $a->duration_ms }} ms</td>
                            <td style="font-family:ui-monospace,monospace;font-size:11px;color:#6B7686">{{ \Illuminate\Support\Str::limit($a->correlation_id, 8, '') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;color:#6B7686;padding:22px">{{ __('Sin actividad registrada todavía.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ============================ CONFIRMACIONES ============================ --}}
    @if ($confirmRotateId !== null)
        <div class="mcp-overlay" wire:click.self="$set('confirmRotateId', null)">
            <div class="mcp-modal" style="width:min(420px,100%)">
                <div class="mcp-modal__head"><h2>{{ __('Rotar clave') }}</h2></div>
                <div class="mcp-modal__body">{{ __('Se generará una clave nueva y la anterior dejará de funcionar de inmediato. ¿Continuar?') }}</div>
                <div class="mcp-modal__foot">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmRotateId', null)">{{ __('Cancelar') }}</button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="rotate">{{ __('Rotar clave') }}</button>
                </div>
            </div>
        </div>
    @endif
    @if ($confirmRevokeId !== null)
        <div class="mcp-overlay" wire:click.self="$set('confirmRevokeId', null)">
            <div class="mcp-modal" style="width:min(420px,100%)">
                <div class="mcp-modal__head"><h2>{{ __('Revocar conexión') }}</h2></div>
                <div class="mcp-modal__body">{{ __('El asistente perderá el acceso de inmediato. Esta acción se puede rehacer creando una conexión nueva. ¿Revocar?') }}</div>
                <div class="mcp-modal__foot">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmRevokeId', null)">{{ __('Cancelar') }}</button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="revoke">{{ __('Revocar') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ WIZARD CREAR ============================ --}}
    @if ($showWizard)
        <div class="mcp-overlay">
            <div class="mcp-modal">
                <div class="mcp-modal__head">
                    <h2>{{ $step === 4 ? __('Conexión creada') : __('Crear conexión') }}</h2>
                    <button type="button" wire:click="closeWizard" style="border:none;background:none;font-size:16px;color:#6B7686;cursor:pointer">✕</button>
                </div>
                <div class="mcp-modal__body">
                    @if ($step < 4)
                        <div class="mcp-steps">
                            <span class="{{ $step >= 1 ? 'on' : '' }}"></span>
                            <span class="{{ $step >= 2 ? 'on' : '' }}"></span>
                            <span class="{{ $step >= 3 ? 'on' : '' }}"></span>
                        </div>
                    @endif

                    @if ($step === 1)
                        <div class="field">
                            <label>{{ __('Nombre de la conexión') }}</label>
                            <input type="text" wire:model="connName" placeholder="{{ __('Ej. ChatGPT Desktop') }}" autocomplete="off">
                            @error('connName') <span class="mca-err">{{ $message }}</span> @enderror
                        </div>
                    @elseif ($step === 2)
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">{{ __('Alcance') }}</label>
                        @if ($isSuper)
                            <div class="mcp-radio">
                                <label>
                                    <input type="radio" wire:model.live="scope" value="global">
                                    <span><strong>{{ __('Acceso global al CRM') }}</strong><br><span style="font-size:12px;color:#6B7686">{{ __('El asistente puede consultar y operar todas las instituciones.') }}</span></span>
                                </label>
                                <label>
                                    <input type="radio" wire:model.live="scope" value="institution">
                                    <span><strong>{{ __('Limitar a una institución') }}</strong><br><span style="font-size:12px;color:#6B7686">{{ __('El asistente solo verá y operará esa institución.') }}</span></span>
                                </label>
                            </div>
                            @if ($scope === 'institution')
                                <div class="field" style="margin-top:12px">
                                    <label>{{ __('Institución') }}</label>
                                    <select wire:model="scopeInstitutionId">
                                        <option value="">{{ __('Selecciona…') }}</option>
                                        @foreach ($institutions as $inst)
                                            <option value="{{ $inst->id }}">{{ $inst->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('scopeInstitutionId') <span class="mca-err">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        @else
                            <div class="mcp-warn">{{ __('La conexión quedará limitada a tu institución.') }}</div>
                        @endif
                    @elseif ($step === 3)
                        <p style="font-size:13.5px;line-height:1.6">{{ __('Se generará una clave de acceso única para esta conexión. Podrás copiarla y descargar la configuración en el siguiente paso.') }}</p>
                        <div class="mcp-ind__item" style="margin-top:10px">
                            <div class="mcp-ind__k">{{ __('Resumen') }}</div>
                            <div style="font-size:13px;margin-top:4px">
                                {{ __('Nombre') }}: <strong>{{ $connName }}</strong><br>
                                {{ __('Alcance') }}: <strong>{{ $scope === 'global' ? __('Global') : __('Institución') }}</strong>
                            </div>
                        </div>
                    @elseif ($step === 4)
                        <p style="font-size:13.5px;font-weight:600;margin:0 0 8px">{{ __('Clave de acceso de ":n"', ['n' => $freshClientName]) }}</p>
                        <div class="mcp-token" x-data="{ v: @js($freshToken) }" x-text="v"></div>
                        <div class="mcp-warn">{{ __('Guarda esta clave. Por seguridad no volverá a mostrarse.') }}</div>
                        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap"
                             x-data="{ v: @js($freshToken), copied: false }">
                            <button type="button" class="btn btn-soft btn-sm"
                                    x-on:click="navigator.clipboard.writeText(v); copied=true; setTimeout(()=>copied=false,1500)"
                                    x-text="copied ? @js(__('¡Copiado!')) : @js(__('Copiar clave'))">{{ __('Copiar clave') }}</button>
                            <button type="button" class="btn btn-soft btn-sm" wire:click="downloadConfig">{{ __('Descargar configuración') }}</button>
                        </div>
                    @endif
                </div>
                <div class="mcp-modal__foot">
                    @if ($step === 1)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="closeWizard">{{ __('Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="nextStep">{{ __('Siguiente') }}</button>
                    @elseif ($step === 2)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="prevStep">{{ __('Atrás') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="nextStep">{{ __('Siguiente') }}</button>
                    @elseif ($step === 3)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="prevStep">{{ __('Atrás') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="createConnection">{{ __('Crear credencial') }}</button>
                    @elseif ($step === 4)
                        <button type="button" class="btn btn-primary btn-sm" wire:click="closeWizard">{{ __('Terminar') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
