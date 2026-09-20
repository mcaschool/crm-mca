<div>
    <x-ui.styles />
    @push('styles')
        <style>
            .mcp-tabs{display:flex;gap:6px;margin:0 0 18px}
            .mcp-tab{border:1px solid #E6EAF0;background:#fff;border-radius:9px;padding:7px 14px;font-size:13px;font-weight:600;color:#6B7686;cursor:pointer;font-family:inherit}
            .mcp-tab.on{background:#1E5AA8;border-color:#1E5AA8;color:#fff}
            .mcp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
            .mcp-card{border:1px solid #E6EAF0;border-radius:14px;padding:18px;background:#fff;display:flex;flex-direction:column}
            .mcp-card__logo{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px}
            .mcp-card h3{margin:12px 0 4px;font-size:16px;font-weight:700}
            .mcp-card p{margin:0;font-size:13px;color:#6B7686;line-height:1.5;flex:1}
            .mcp-card__state{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;margin:12px 0}
            .mcp-dot{width:8px;height:8px;border-radius:50%}
            .dot-on{background:#1F7A3D}.dot-off{background:#C6D0DD}
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
            .mcp-check{display:flex;align-items:center;gap:7px;font-size:13px;color:#1F7A3D;margin:4px 0}
            .mcp-tech{margin-top:22px;font-size:12.5px;color:#6B7686}
            .mcp-tech summary{cursor:pointer;font-weight:600}
            .mcp-radio{display:flex;flex-direction:column;gap:8px}
            .mcp-radio label{display:flex;gap:9px;align-items:flex-start;border:1px solid #E6EAF0;border-radius:10px;padding:11px 13px;cursor:pointer}
            .mcp-mono{font-family:ui-monospace,monospace;font-size:12px;background:#F4F6F9;border-radius:8px;padding:8px 10px;word-break:break-all}
            .mcp-badge{display:inline-flex;border-radius:7px;padding:2px 8px;font-size:11px;font-weight:700;border:1px solid #E6EAF0;background:#F8FAFC;color:#4A5568}
        </style>
    @endpush
    @php
        $logo = ['chatgpt' => ['#10A37F', 'GPT'], 'claude' => ['#D97757', 'Cl'], 'claude_code' => ['#1F2A37', '</>']];
        $label = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'claude_code' => 'Claude Code'];
    @endphp

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">{{ __('Conexiones IA') }}</h1>
                <p class="mca-sub">{{ __('Conecta asistentes de inteligencia artificial con MCA CRM de forma segura.') }}</p>
            </div>
        </div>

        @if (session('mcpStatus'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('mcpStatus') }}</div>
        @endif

        <div class="mcp-tabs">
            <button type="button" class="mcp-tab {{ $tab === 'dashboard' ? 'on' : '' }}" wire:click="setTab('dashboard')">{{ __('Asistentes') }}</button>
            <button type="button" class="mcp-tab {{ $tab === 'connections' ? 'on' : '' }}" wire:click="setTab('connections')">{{ __('Conexiones') }}</button>
            <button type="button" class="mcp-tab {{ $tab === 'activity' ? 'on' : '' }}" wire:click="setTab('activity')">{{ __('Actividad') }}</button>
        </div>

        {{-- ============================ ASISTENTES ============================ --}}
        @if ($tab === 'dashboard')
            <div class="mcp-cards">
                {{-- ChatGPT --}}
                <div class="mcp-card">
                    <span class="mcp-card__logo" style="background:{{ $logo['chatgpt'][0] }}">{{ $logo['chatgpt'][1] }}</span>
                    <h3>ChatGPT</h3>
                    <p>{{ __('Consulta e inspecciona MCA CRM desde ChatGPT.') }}</p>
                    <span class="mcp-card__state"><span class="mcp-dot {{ $presets['chatgpt'] ? 'dot-on' : 'dot-off' }}"></span>{{ $presets['chatgpt'] ? __('Conectado') : __('No conectado') }}</span>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="showPreset('chatgpt')">{{ __('Conectar ChatGPT') }}</button>
                </div>
                {{-- Claude --}}
                <div class="mcp-card">
                    <span class="mcp-card__logo" style="background:{{ $logo['claude'][0] }}">{{ $logo['claude'][1] }}</span>
                    <h3>Claude</h3>
                    <p>{{ __('Consulta e inspecciona MCA CRM desde Claude.') }}</p>
                    <span class="mcp-card__state"><span class="mcp-dot {{ $presets['claude'] ? 'dot-on' : 'dot-off' }}"></span>{{ $presets['claude'] ? __('Conectado') : __('No conectado') }}</span>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="showPreset('claude')">{{ __('Conectar Claude') }}</button>
                </div>
                {{-- Claude Code --}}
                <div class="mcp-card">
                    <span class="mcp-card__logo" style="background:{{ $logo['claude_code'][0] }}">{{ $logo['claude_code'][1] }}</span>
                    <h3>Claude Code</h3>
                    <p>{{ __('Accede al contexto técnico de MCA CRM desde Claude Code.') }}</p>
                    <span class="mcp-card__state"><span class="mcp-dot {{ $presets['claude_code'] ? 'dot-on' : 'dot-off' }}"></span>{{ $presets['claude_code'] ? __('Conectado') : __('No conectado') }}</span>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="connectClaudeCode">{{ __('Conectar Claude Code') }}</button>
                </div>
            </div>

            <details class="mcp-tech">
                <summary>{{ __('Información técnica') }}</summary>
                <div style="margin-top:8px;line-height:1.8">
                    <div>{{ __('Endpoint MCP') }}: <span class="mcp-mono">{{ $tech['endpoint'] }}</span></div>
                    <div>{{ __('Protocolo') }}: Streamable HTTP (JSON-RPC 2.0) · HTTPS: {{ $tech['https'] ? __('Válido') : __('No') }}</div>
                    <div>{{ __('Herramientas expuestas') }}: {{ $tech['tools'] }} · {{ __('Última actividad') }}: {{ $tech['last_activity']?->diffForHumans() ?? '—' }}</div>
                    <div>{{ __('Autenticación') }}: Bearer (Claude Code) · OAuth para ChatGPT/Claude en preparación.</div>
                    <div>{{ __('Alta por consola') }}: <span class="mcp-mono">php artisan mcp:client</span></div>
                </div>
            </details>

        {{-- ============================ CONEXIONES ============================ --}}
        @elseif ($tab === 'connections')
            <div class="card card-p">
                <table class="mcp-table">
                    <thead><tr>
                        <th>{{ __('Asistente') }}</th><th>{{ __('Nombre') }}</th><th>{{ __('Institución') }}</th>
                        <th>{{ __('Acceso') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Última actividad') }}</th>
                        <th>{{ __('Creado') }}</th><th>{{ __('Acciones') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($connections as $c)
                        <tr wire:key="conn-{{ $c['id'] }}">
                            <td><span class="mcp-badge">{{ $label[$c['assistant']] ?? $c['assistant'] }}</span></td>
                            <td style="font-weight:600">{{ $c['name'] }}</td>
                            <td>{{ $c['institution'] }}</td>
                            <td>{{ $c['access'] }}</td>
                            <td><span class="badge {{ $c['state'] === 'connected' ? 'badge-on' : 'badge-off' }}">{{ $c['state'] === 'connected' ? __('Conectado') : ($c['state'] === 'pending' ? __('Pendiente de conexión') : __('Desconectado')) }}</span></td>
                            <td>{{ $c['last_used']?->diffForHumans() ?? __('nunca') }}</td>
                            <td>{{ $c['created']?->format('d/m/Y') ?? '—' }}</td>
                            <td style="white-space:nowrap">
                                @if ($c['active'])
                                    <button type="button" class="btn btn-soft btn-sm" wire:click="testServer">{{ __('Probar') }}</button>
                                    @if ($c['technical'])
                                        <button type="button" class="btn btn-soft btn-sm" wire:click="togglePermissions({{ $c['id'] }})">
                                            {{ $c['allow_write'] ? __('Quitar escritura') : __('Permitir acciones técnicas') }}
                                        </button>
                                    @endif
                                    @if ($c['bearer'])
                                        <button type="button" class="btn btn-soft btn-sm" wire:click="askRotate({{ $c['id'] }})">{{ __('Rotar clave') }}</button>
                                    @endif
                                    <button type="button" class="btn btn-soft btn-sm" wire:click="$set('fClient', '{{ $c['id'] }}'); $set('tab', 'activity')">{{ __('Ver actividad') }}</button>
                                    <button type="button" class="btn btn-soft btn-sm" wire:click="askRevoke({{ $c['id'] }})">{{ __('Desconectar') }}</button>
                                @else
                                    <span style="color:#6B7686;font-size:12px">{{ __('sin acceso') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="text-align:center;color:#6B7686;padding:22px">{{ __('Aún no hay conexiones. Empieza en «Asistentes».') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        {{-- ============================ ACTIVIDAD ============================ --}}
        @elseif ($tab === 'activity')
            <div class="card card-p">
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                    <select wire:model.live="fClient" style="max-width:200px">
                        <option value="">{{ __('Todas las conexiones') }}</option>
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
                        <th>{{ __('Resultado') }}</th><th>{{ __('Duración') }}</th><th>Correlation</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($activity as $a)
                        <tr wire:key="act-{{ $a->id }}">
                            <td>{{ $a->created_at?->format('d/m H:i:s') }}</td>
                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $a->tool }}</td>
                            <td>{{ $a->action ?: '—' }}</td>
                            <td><span style="color:{{ $a->status === 'ok' ? '#1F7A3D' : '#8A1C1C' }}">{{ $a->status === 'ok' ? __('Éxito') : __('Error') }}</span></td>
                            <td>{{ $a->duration_ms }} ms</td>
                            <td style="font-family:ui-monospace,monospace;font-size:11px;color:#6B7686">{{ \Illuminate\Support\Str::limit($a->correlation_id, 8, '') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:#6B7686;padding:22px">{{ __('Sin actividad registrada todavía.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ============ INFO ChatGPT / Claude (OAuth en preparación) ============ --}}
    @if ($showInfo)
        <div class="mcp-overlay" wire:click.self="closePreset">
            <div class="mcp-modal" style="width:min(480px,100%)">
                <div class="mcp-modal__head">
                    <h2>{{ $showInfo === 'chatgpt' ? 'ChatGPT' : 'Claude' }}</h2>
                    <button type="button" wire:click="closePreset" style="border:none;background:none;font-size:16px;color:#6B7686;cursor:pointer">✕</button>
                </div>
                <div class="mcp-modal__body">
                    <div class="mcp-check">✓ {{ __('Servicio preparado') }}</div>
                    <div class="mcp-check">✓ {{ __('Acceso de lectura / inspección') }}</div>
                    <div class="mcp-warn">
                        {{ __('La conexión guiada y segura (sin copiar claves) se habilitará en una próxima actualización. Mientras tanto, para uso técnico puedes conectar Claude Code.') }}
                    </div>
                    @if ($showInfo === 'claude')
                        <p style="font-size:13px;color:#6B7686;margin-top:12px">{{ __('En Claude será: Configuración → Conectores → Agregar conector → MCA CRM → Conectar.') }}</p>
                    @endif
                </div>
                <div class="mcp-modal__foot">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="closePreset">{{ __('Entendido') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ CONFIRMACIONES ============ --}}
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
                <div class="mcp-modal__head"><h2>{{ __('Desconectar') }}</h2></div>
                <div class="mcp-modal__body">{{ __('El asistente perderá el acceso de inmediato. Podrás volver a conectar cuando quieras. ¿Desconectar?') }}</div>
                <div class="mcp-modal__foot">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmRevokeId', null)">{{ __('Cancelar') }}</button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="revoke">{{ __('Desconectar') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ WIZARD CLAUDE CODE ============ --}}
    @if ($showWizard)
        <div class="mcp-overlay">
            <div class="mcp-modal">
                <div class="mcp-modal__head">
                    <h2>{{ $step === 4 ? __('Claude Code conectado') : __('Conectar Claude Code') }}</h2>
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
                            <input type="text" wire:model="connName" placeholder="{{ __('Ej. Claude Code Oficina') }}" autocomplete="off">
                            @error('connName') <span class="mca-err">{{ $message }}</span> @enderror
                        </div>
                    @elseif ($step === 2)
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">{{ __('Alcance') }}</label>
                        @if ($isSuper)
                            <div class="mcp-radio">
                                <label><input type="radio" wire:model.live="scope" value="global"><span><strong>{{ __('Acceso global al CRM') }}</strong></span></label>
                                <label><input type="radio" wire:model.live="scope" value="institution"><span><strong>{{ __('Limitar a una institución') }}</strong></span></label>
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
                        <p style="font-size:13.5px;line-height:1.6">{{ __('Se generará una clave para conectar Claude Code. Tendrá acceso técnico de LECTURA; las acciones de escritura quedan desactivadas hasta que las autorices en «Conexiones».') }}</p>
                        <div class="card card-p" style="margin-top:10px">
                            {{ __('Nombre') }}: <strong>{{ $connName }}</strong><br>
                            {{ __('Acceso') }}: <strong>{{ __('Técnico (solo lectura)') }}</strong>
                        </div>
                    @elseif ($step === 4)
                        <div class="mcp-check">✓ {{ __('Conexión creada') }}: <strong>{{ $freshClientName }}</strong></div>
                        <p style="font-size:13px;font-weight:600;margin:12px 0 6px">{{ __('Clave de acceso (se muestra una sola vez)') }}</p>
                        <div class="mcp-token" x-data="{ v: @js($freshToken) }" x-text="v"></div>
                        <div class="mcp-warn">{{ __('Guarda esta clave. Por seguridad no volverá a mostrarse.') }}</div>

                        <p style="font-size:13.5px;font-weight:600;margin:16px 0 8px">{{ __('Instalación guiada') }}</p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"
                             x-data="{ v: @js($freshToken), copied: false }">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="downloadInstaller('windows')">{{ __('Descargar instalador Windows') }}</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="downloadInstaller('unix')">{{ __('Descargar instalador macOS/Linux') }}</button>
                            <button type="button" class="btn btn-soft btn-sm"
                                    x-on:click="navigator.clipboard.writeText(v); copied=true; setTimeout(()=>copied=false,1500)"
                                    x-text="copied ? @js(__('¡Copiado!')) : @js(__('Copiar clave'))">{{ __('Copiar clave') }}</button>
                        </div>

                        <details style="margin-top:14px;font-size:12.5px;color:#6B7686">
                            <summary style="cursor:pointer;font-weight:600">{{ __('Configuración manual (usuarios técnicos)') }}</summary>
                            <div style="margin-top:8px">
                                <div>{{ __('En una terminal con Claude Code instalado:') }}</div>
                                <div class="mcp-mono" style="margin-top:6px">claude mcp add mca-crm --transport http "{{ $tech['endpoint'] }}" --header "Authorization: Bearer {{ $freshToken }}"</div>
                            </div>
                        </details>
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
