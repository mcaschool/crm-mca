<div class="mca-page">
    @php
        // Identificador visual de cada asistente (iniciales sobre un tono suave del sistema).
        $logo = ['chatgpt' => ['teal', 'GPT'], 'claude' => ['gold', 'Cl'], 'claude_code' => ['deep', '</>']];
        $label = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'claude_code' => 'Claude Code'];
        $cards = [
            'chatgpt' => [__('Consulta e inspecciona MCA CRM desde ChatGPT.'), __('Conectar ChatGPT'), "showPreset('chatgpt')"],
            'claude' => [__('Consulta e inspecciona MCA CRM desde Claude.'), __('Conectar Claude'), "showPreset('claude')"],
            'claude_code' => [__('Accede al contexto técnico de MCA CRM desde Claude Code.'), __('Conectar Claude Code'), 'connectClaudeCode'],
        ];
    @endphp

    <x-ui.page-header :title="__('Conexiones IA')" :subtitle="__('Conecta asistentes de inteligencia artificial con MCA CRM de forma segura.')" />

    @if (session('mcpStatus'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('mcpStatus') }}</div>
    @endif

    <div class="ui-stack">
        <div class="ui-seg ui-seg--nav" role="group" aria-label="{{ __('Secciones') }}">
            <button type="button" aria-pressed="{{ $tab === 'dashboard' ? 'true' : 'false' }}" wire:click="setTab('dashboard')">{{ __('Asistentes') }}</button>
            <button type="button" aria-pressed="{{ $tab === 'connections' ? 'true' : 'false' }}" wire:click="setTab('connections')">{{ __('Conexiones') }}</button>
            <button type="button" aria-pressed="{{ $tab === 'activity' ? 'true' : 'false' }}" wire:click="setTab('activity')">{{ __('Actividad') }}</button>
        </div>

        {{-- ============================ ASISTENTES ============================ --}}
        @if ($tab === 'dashboard')
            <div class="ui-grid">
                @foreach ($cards as $key => [$desc, $cta, $action])
                    <article class="ui-card ui-entity" wire:key="mcp-card-{{ $key }}">
                        <div class="ui-entity-h">
                            <span class="ui-icon-tile ui-icon-tile--text ui-tone--{{ $logo[$key][0] }}" aria-hidden="true">{{ $logo[$key][1] }}</span>
                            <div class="ui-person-text">
                                <h2 class="ui-entity-title">{{ $label[$key] }}</h2>
                            </div>
                            <x-ui.badge :tone="$presets[$key] ? 'success' : 'neutral'" dot>{{ $presets[$key] ? __('Conectado') : __('No conectado') }}</x-ui.badge>
                        </div>
                        <div class="ui-entity-b">{{ $desc }}</div>
                        <div class="ui-card-f">
                            <x-ui.button size="sm" :variant="$key === 'claude_code' ? 'primary' : 'secondary'" icon="plug" wire:click="{{ $action }}">{{ $cta }}</x-ui.button>
                        </div>
                    </article>
                @endforeach
            </div>

            <details class="ui-card ui-disclosure">
                <summary class="ui-card-h">
                    <x-ui.icon name="cpu" class="ui-card-ic" /><h2>{{ __('Información técnica') }}</h2>
                    <span class="sp"></span><x-ui.icon name="chevron-down" class="ui-disclosure-chev" />
                </summary>
                <div class="ui-card-p">
                    <dl class="ui-meta ui-meta--spaced">
                        <dt>{{ __('Endpoint MCP') }}</dt><dd><span class="ui-mono">{{ $tech['endpoint'] }}</span></dd>
                        <dt>{{ __('Protocolo') }}</dt><dd>Streamable HTTP (JSON-RPC 2.0)</dd>
                        <dt>HTTPS</dt><dd><x-ui.badge :tone="$tech['https'] ? 'success' : 'warning'">{{ $tech['https'] ? __('Válido') : __('No') }}</x-ui.badge></dd>
                        <dt>{{ __('Herramientas expuestas') }}</dt><dd>{{ $tech['tools'] }}</dd>
                        <dt>{{ __('Última actividad') }}</dt><dd>{{ $tech['last_activity']?->diffForHumans() ?? '—' }}</dd>
                        <dt>{{ __('Autenticación') }}</dt><dd>{{ __('Bearer (Claude Code) · OAuth para ChatGPT/Claude en preparación.') }}</dd>
                        <dt>{{ __('Alta por consola') }}</dt><dd><span class="ui-mono">php artisan mcp:client</span></dd>
                    </dl>
                </div>
            </details>

        {{-- ============================ CONEXIONES ============================ --}}
        @elseif ($tab === 'connections')
            <section class="ui-card" aria-labelledby="mcp-conn-title">
                <div class="ui-card-h"><x-ui.icon name="plug" class="ui-card-ic" /><h2 id="mcp-conn-title">{{ __('Conexiones') }}</h2>
                    <x-ui.badge tone="neutral" class="ui-badge--count">{{ count($connections) }}</x-ui.badge>
                </div>
                <ul class="ui-list">
                    @forelse ($connections as $c)
                        @php
                            [$stTone, $stText] = match ($c['state']) {
                                'connected' => ['success', __('Conectado')],
                                'pending' => ['warning', __('Pendiente de conexión')],
                                default => ['neutral', __('Desconectado')],
                            };
                        @endphp
                        <li wire:key="conn-{{ $c['id'] }}">
                            <div class="ui-list-item ui-split">
                                <div class="ui-split-main">
                                    <span class="ui-person-line">
                                        <span class="ui-list-title">{{ $c['name'] }}</span>
                                        <x-ui.badge tone="neutral">{{ $label[$c['assistant']] ?? $c['assistant'] }}</x-ui.badge>
                                        <x-ui.badge :tone="$stTone" dot>{{ $stText }}</x-ui.badge>
                                    </span>
                                    <span class="ui-list-meta">
                                        {{ __('Institución') }}: <b>{{ $c['institution'] }}</b> · {{ __('Acceso') }}: <b>{{ $c['access'] }}</b>
                                        · {{ __('Última actividad') }}: <b>{{ $c['last_used']?->diffForHumans() ?? __('nunca') }}</b>
                                        · {{ __('Creado') }}: <b>{{ $c['created']?->format('d/m/Y') ?? '—' }}</b>
                                    </span>
                                </div>
                                <div class="ui-actions-row">
                                    @if ($c['active'])
                                        <x-ui.button size="sm" icon="refresh" wire:click="testServer">{{ __('Probar') }}</x-ui.button>
                                        @if ($c['technical'])
                                            <x-ui.button size="sm" icon="shield" wire:click="togglePermissions({{ $c['id'] }})">
                                                {{ $c['allow_write'] ? __('Quitar escritura') : __('Permitir acciones técnicas') }}
                                            </x-ui.button>
                                        @endif
                                        @if ($c['bearer'])
                                            <x-ui.button size="sm" icon="lock" wire:click="askRotate({{ $c['id'] }})">{{ __('Rotar clave') }}</x-ui.button>
                                        @endif
                                        <x-ui.button size="sm" icon="activity" wire:click="$set('fClient', '{{ $c['id'] }}'); $set('tab', 'activity')">{{ __('Ver actividad') }}</x-ui.button>
                                        <x-ui.button size="sm" variant="danger" icon="power" wire:click="askRevoke({{ $c['id'] }})">{{ __('Desconectar') }}</x-ui.button>
                                    @else
                                        <span class="ui-list-meta">{{ __('sin acceso') }}</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="ui-empty">
                            <div class="ui-empty-ic"><x-ui.icon name="plug" /></div>
                            <b>{{ __('Aún no hay conexiones. Empieza en «Asistentes».') }}</b>
                        </li>
                    @endforelse
                </ul>
            </section>

        {{-- ============================ ACTIVIDAD ============================ --}}
        @elseif ($tab === 'activity')
            <div class="ui-card ui-card--list">
                <div class="ui-toolbar">
                    <select class="ui-select ui-select--filter {{ $fClient !== '' ? 'is-active' : '' }}" wire:model.live="fClient" aria-label="{{ __('Conexión') }}">
                        <option value="">{{ __('Todas las conexiones') }}</option>
                        @foreach ($clientsForFilter as $cf)
                            <option value="{{ $cf->id }}">{{ $cf->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.search wire:model.live.debounce.300ms="fTool" placeholder="{{ __('Herramienta…') }}" aria-label="{{ __('Herramienta') }}" />
                    <select class="ui-select ui-select--filter {{ $fStatus !== '' ? 'is-active' : '' }}" wire:model.live="fStatus" aria-label="{{ __('Resultado') }}">
                        <option value="">{{ __('Todos') }}</option>
                        <option value="ok">{{ __('Éxito') }}</option>
                        <option value="error">{{ __('Error') }}</option>
                    </select>
                </div>
                <div class="mca-table-scroll">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ __('Fecha') }}</th><th>{{ __('Herramienta') }}</th><th>{{ __('Acción') }}</th>
                                <th>{{ __('Resultado') }}</th><th>{{ __('Duración') }}</th><th>Correlation</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activity as $a)
                                <tr wire:key="act-{{ $a->id }}">
                                    <td class="t-2" data-label="{{ __('Fecha') }}">{{ $a->created_at?->userTz()->format('d/m H:i:s') }}</td>
                                    <td class="rt-primary"><span class="ui-mono">{{ $a->tool }}</span></td>
                                    <td class="t-2" data-label="{{ __('Acción') }}">{{ $a->action ?: '—' }}</td>
                                    <td class="rt-aside" data-label="{{ __('Resultado') }}">
                                        <x-ui.badge :tone="$a->status === 'ok' ? 'success' : 'danger'" dot>{{ $a->status === 'ok' ? __('Éxito') : __('Error') }}</x-ui.badge>
                                    </td>
                                    <td class="t-2" data-label="{{ __('Duración') }}">{{ $a->duration_ms }} ms</td>
                                    <td class="t-3" data-label="Correlation"><span class="ui-mono">{{ \Illuminate\Support\Str::limit($a->correlation_id, 8, '') }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="rt-empty">
                                        <div class="ui-empty">
                                            <div class="ui-empty-ic"><x-ui.icon name="activity" /></div>
                                            <b>{{ __('Sin actividad registrada todavía.') }}</b>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- ============ INFO ChatGPT / Claude (OAuth en preparación) ============ --}}
    @if ($showInfo)
        <div class="ui-modal-bg" wire:click.self="closePreset">
            <div class="ui-modal ui-modal--xs" role="dialog" aria-modal="true" aria-labelledby="mcp-info-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="mcp-info-title">{{ $showInfo === 'chatgpt' ? 'ChatGPT' : 'Claude' }}</h2>
                    <x-ui.button variant="ghost" size="sm" icon="x" wire:click="closePreset" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    <ul class="ui-checklist">
                        <li class="is-ok"><b aria-hidden="true">✓</b> {{ __('Servicio preparado') }}</li>
                        <li class="is-ok"><b aria-hidden="true">✓</b> {{ __('Acceso de lectura / inspección') }}</li>
                    </ul>
                    <div class="ui-callout ui-tone--blue"><x-ui.icon name="clock" /> <span>{{ __('La conexión guiada y segura (sin copiar claves) se habilitará en una próxima actualización. Mientras tanto, para uso técnico puedes conectar Claude Code.') }}</span></div>
                    @if ($showInfo === 'claude')
                        <p class="ui-help">{{ __('En Claude será: Configuración → Conectores → Agregar conector → MCA CRM → Conectar.') }}</p>
                    @endif
                </div>
                <div class="ui-modal-f">
                    <x-ui.button variant="primary" size="sm" wire:click="closePreset">{{ __('Entendido') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ CONFIRMACIONES ============ --}}
    @if ($confirmRotateId !== null)
        <div class="ui-modal-bg" wire:click.self="$set('confirmRotateId', null)">
            <div class="ui-modal ui-modal--xs" role="alertdialog" aria-modal="true" aria-labelledby="mcp-rotate-title">
                <div class="ui-modal-h"><h2 class="ui-modal-title" id="mcp-rotate-title">{{ __('Rotar clave') }}</h2></div>
                <div class="ui-modal-b"><p class="ui-modal-text">{{ __('Se generará una clave nueva y la anterior dejará de funcionar de inmediato. ¿Continuar?') }}</p></div>
                <div class="ui-modal-f">
                    <x-ui.button size="sm" wire:click="$set('confirmRotateId', null)">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button variant="primary" size="sm" icon="lock" wire:click="rotate">{{ __('Rotar clave') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif
    @if ($confirmRevokeId !== null)
        <div class="ui-modal-bg" wire:click.self="$set('confirmRevokeId', null)">
            <div class="ui-modal ui-modal--xs" role="alertdialog" aria-modal="true" aria-labelledby="mcp-revoke-title">
                <div class="ui-modal-h"><h2 class="ui-modal-title" id="mcp-revoke-title">{{ __('Desconectar') }}</h2></div>
                <div class="ui-modal-b"><p class="ui-modal-text">{{ __('El asistente perderá el acceso de inmediato. Podrás volver a conectar cuando quieras. ¿Desconectar?') }}</p></div>
                <div class="ui-modal-f">
                    <x-ui.button size="sm" wire:click="$set('confirmRevokeId', null)">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button variant="danger" size="sm" icon="power" wire:click="revoke">{{ __('Desconectar') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ WIZARD CLAUDE CODE ============ --}}
    @if ($showWizard)
        <div class="ui-modal-bg">
            <div class="ui-modal ui-modal--sm" role="dialog" aria-modal="true" aria-labelledby="mcp-wiz-title">
                <div class="ui-modal-h">
                    <div>
                        <h2 class="ui-modal-title" id="mcp-wiz-title">{{ $step === 4 ? __('Claude Code conectado') : __('Conectar Claude Code') }}</h2>
                        @if ($step < 4)
                            <p class="ui-modal-sub">{{ __('Paso :n de 3', ['n' => $step]) }}</p>
                        @endif
                    </div>
                    <x-ui.button variant="ghost" size="sm" icon="x" wire:click="closeWizard" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    @if ($step < 4)
                        <ol class="ui-steps ui-steps--3">
                            @foreach ([1 => __('Nombre'), 2 => __('Alcance'), 3 => __('Confirmar')] as $n => $stepLabel)
                                <li @class(['is-current' => $step === $n]) @if ($step === $n) aria-current="step" @endif>
                                    <span @class(['ui-step-n', 'is-done' => $step > $n, 'is-current' => $step === $n, 'is-pending' => $step < $n])>@if ($step > $n)<x-ui.icon name="check" />@else{{ $n }}@endif</span>
                                    <span>{{ $stepLabel }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    @if ($step === 1)
                        <x-ui.field :label="__('Nombre de la conexión')" for="mcp-conn-name" required :error="$errors->first('connName')">
                            <input id="mcp-conn-name" type="text" wire:model="connName" maxlength="60" placeholder="{{ __('Ej. Claude Code Oficina') }}" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('connName')]) @error('connName') aria-describedby="mcp-conn-name-error" @enderror>
                        </x-ui.field>
                    @elseif ($step === 2)
                        <div class="ui-field">
                            <span class="ui-label">{{ __('Alcance') }}</span>
                            @if ($isSuper)
                                <div class="ui-nets">
                                    <label class="ui-net"><input type="radio" wire:model.live="scope" value="global"><span class="ui-net-text"><b>{{ __('Acceso global al CRM') }}</b></span></label>
                                    <label class="ui-net"><input type="radio" wire:model.live="scope" value="institution"><span class="ui-net-text"><b>{{ __('Limitar a una institución') }}</b></span></label>
                                </div>
                            @else
                                <div class="ui-callout ui-tone--blue"><x-ui.icon name="building-2" /> <span>{{ __('La conexión quedará limitada a tu institución.') }}</span></div>
                            @endif
                        </div>
                        @if ($isSuper && $scope === 'institution')
                            <x-ui.field :label="__('Institución')" for="mcp-scope-inst" required :error="$errors->first('scopeInstitutionId')">
                                <select id="mcp-scope-inst" wire:model="scopeInstitutionId" @class(['ui-select', 'is-invalid' => $errors->has('scopeInstitutionId')])>
                                    <option value="">{{ __('Selecciona…') }}</option>
                                    @foreach ($institutions as $inst)
                                        <option value="{{ $inst->id }}">{{ $inst->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                    @elseif ($step === 3)
                        <p class="ui-modal-text">{{ __('Se generará una clave para conectar Claude Code. Tendrá acceso técnico de LECTURA; las acciones de escritura quedan desactivadas hasta que las autorices en «Conexiones».') }}</p>
                        <dl class="ui-meta ui-box">
                            <dt>{{ __('Nombre') }}</dt><dd><b>{{ $connName }}</b></dd>
                            <dt>{{ __('Acceso') }}</dt><dd><b>{{ __('Técnico (solo lectura)') }}</b></dd>
                        </dl>
                    @elseif ($step === 4)
                        <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> <span>{{ __('Conexión creada') }}: <strong>{{ $freshClientName }}</strong></span></div>
                        <div class="ui-field">
                            <span class="ui-label">{{ __('Clave de acceso (se muestra una sola vez)') }}</span>
                            <div class="ui-copy"><span class="ui-copy-text ui-select-all" x-data="{ v: @js($freshToken) }" x-text="v"></span></div>
                        </div>
                        <div class="ui-callout ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ __('Guarda esta clave. Por seguridad no volverá a mostrarse.') }}</span></div>

                        <div class="ui-field">
                            <span class="ui-label">{{ __('Instalación guiada') }}</span>
                            <div class="ui-actions-row" x-data="{ v: @js($freshToken), copied: false }">
                                <x-ui.button size="sm" icon="download" wire:click="downloadInstaller('windows')">{{ __('Descargar instalador Windows') }}</x-ui.button>
                                <x-ui.button size="sm" icon="download" wire:click="downloadInstaller('unix')">{{ __('Descargar instalador macOS/Linux') }}</x-ui.button>
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm"
                                        x-on:click="navigator.clipboard.writeText(v); copied=true; setTimeout(()=>copied=false,1500)"
                                        x-text="copied ? @js(__('¡Copiado!')) : @js(__('Copiar clave'))">{{ __('Copiar clave') }}</button>
                            </div>
                        </div>

                        <details class="ui-disclosure">
                            <summary class="ui-disclosure-sum"><x-ui.icon name="chevron-down" class="ui-disclosure-chev" /> {{ __('Configuración manual (usuarios técnicos)') }}</summary>
                            <div class="ui-disclosure-b">
                                <div class="ui-code">
                                    <div class="ui-code-h"><span>{{ __('En una terminal con Claude Code instalado:') }}</span></div>
                                    <pre>claude mcp add mca-crm --transport http "{{ $tech['endpoint'] }}" --header "Authorization: Bearer {{ $freshToken }}"</pre>
                                </div>
                            </div>
                        </details>
                    @endif
                </div>
                <div class="ui-modal-f">
                    @if ($step === 1)
                        <x-ui.button size="sm" wire:click="closeWizard">{{ __('Cancelar') }}</x-ui.button>
                        <x-ui.button variant="primary" size="sm" wire:click="nextStep">{{ __('Siguiente') }}</x-ui.button>
                    @elseif ($step === 2)
                        <x-ui.button size="sm" wire:click="prevStep">{{ __('Atrás') }}</x-ui.button>
                        <x-ui.button variant="primary" size="sm" wire:click="nextStep">{{ __('Siguiente') }}</x-ui.button>
                    @elseif ($step === 3)
                        <x-ui.button size="sm" wire:click="prevStep">{{ __('Atrás') }}</x-ui.button>
                        <x-ui.button variant="primary" size="sm" icon="lock" wire:click="createConnection">{{ __('Crear credencial') }}</x-ui.button>
                    @elseif ($step === 4)
                        <x-ui.button variant="primary" size="sm" wire:click="closeWizard">{{ __('Terminar') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
