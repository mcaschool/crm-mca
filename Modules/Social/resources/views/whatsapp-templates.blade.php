<div>
    <x-ui.styles />
    @push('styles')
        <style>
            .wt-badge{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:700;letter-spacing:.02em;border:1px solid #E6EAF0;background:#F8FAFC;color:#6B7686}
            .wt-badge--approved{background:#E8F5EC;border-color:#BFE3C9;color:#1F7A3D}
            .wt-badge--pending{background:#FFF7E6;border-color:#F0DFAE;color:#8A6D1B}
            .wt-badge--rejected{background:#FCE9E9;border-color:#F1C4C4;color:#8A1C1C}
            .wt-badge--paused{background:#EEF2F7;border-color:#D8DFE9;color:#4A5568}
            .wt-badge--archived{background:#F4F4F5;border-color:#E4E4E7;color:#71717A}
            .wt-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border:1px solid #E6EAF0;border-radius:12px;background:#fff;cursor:pointer;width:100%;text-align:left;font-family:inherit;transition:border-color .12s}
            .wt-row:hover{border-color:#1E5AA8}
            .wt-row + .wt-row{margin-top:10px}
            .wt-row__name{font-weight:700;font-size:14px;color:#1F2A37}
            .wt-row__meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:3px}
            .wt-row__sync{margin-left:auto;font-size:11.5px;color:#6B7686;white-space:nowrap}
            .wt-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px;align-items:start}
            @media (max-width: 980px){.wt-grid{grid-template-columns:1fr}}
            .wt-phone{position:sticky;top:14px;background:#E7DFD4;border-radius:22px;padding:16px 14px;min-height:340px;border:1px solid #D8CDBD}
            .wt-phone__bubble{background:#fff;border-radius:12px;border-bottom-left-radius:4px;padding:10px 12px;max-width:100%;box-shadow:0 1px 1px rgba(16,24,40,.08);font-size:13px;line-height:1.5;color:#1F2A37;word-wrap:break-word}
            .wt-phone__header{font-weight:700;margin-bottom:4px}
            .wt-phone__media{background:#F0F2F5;border:1px dashed #C9CFD8;border-radius:9px;padding:22px 10px;text-align:center;font-size:12px;color:#6B7686;margin-bottom:7px}
            .wt-phone__footer{margin-top:6px;font-size:11.5px;color:#8B94A3}
            .wt-phone__btn{margin-top:8px;border-top:1px solid #E6EAF0;padding-top:8px;text-align:center;color:#1E5AA8;font-weight:600;font-size:13px}
            .wt-var-chip{display:inline-flex;background:#EEF4FB;border:1px solid #C9DCF2;color:#1E5AA8;border-radius:7px;padding:1px 7px;font-size:11.5px;font-weight:700}
            .wt-errors{background:#FCE9E9;border:1px solid #F1C4C4;border-radius:10px;padding:11px 14px;margin-bottom:16px}
            .wt-errors li{color:#8A1C1C;font-size:12.5px;margin:2px 0 2px 14px}
            .wt-btnrow{display:flex;align-items:center;gap:8px;margin-top:8px}
            .wt-summary{display:grid;grid-template-columns:150px 1fr;gap:6px 14px;font-size:13px}
            .wt-summary dt{color:#6B7686;font-weight:600}
            .wt-summary dd{margin:0;color:#1F2A37;word-wrap:break-word}
        </style>
    @endpush
    @php
        // Llaves dobles construidas con chr(): un "{{" literal rompería el compilador Blade.
        $vOpen = chr(123).chr(123);
        $vClose = chr(125).chr(125);
        $badge = function (string $status): string {
            return match ($status) {
                'APPROVED' => 'wt-badge--approved',
                'PENDING', 'IN_APPEAL' => 'wt-badge--pending',
                'REJECTED' => 'wt-badge--rejected',
                'PAUSED', 'DISABLED', 'FLAGGED', 'LOCKED', 'LIMIT_EXCEEDED' => 'wt-badge--paused',
                'ARCHIVED', 'DELETED', 'PENDING_DELETION' => 'wt-badge--archived',
                default => '',
            };
        };
    @endphp

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">{{ __('Plantillas de WhatsApp') }}</h1>
                <p class="mca-sub">{{ __('Crea y sincroniza las plantillas de mensaje aprobadas por WhatsApp. Solo las plantillas aprobadas pueden enviarse fuera de la ventana de 24 horas.') }}</p>
            </div>
            <div class="sp" style="flex:1"></div>
            @if ($view === 'list' && $channel)
                <button type="button" wire:click="sync" class="btn btn-sm" wire:loading.attr="disabled" wire:target="sync">
                    <x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" />
                    <span wire:loading.remove wire:target="sync">{{ __('Sincronizar con Meta') }}</span>
                    <span wire:loading wire:target="sync">{{ __('Sincronizando…') }}</span>
                </button>
                @can('create', \Modules\Social\Models\SocialChannel::class)
                    <button type="button" wire:click="startCreate" class="btn btn-primary btn-sm">
                        <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Crear plantilla') }}
                    </button>
                @endcan
            @elseif ($view !== 'list')
                <button type="button" wire:click="back" class="btn btn-sm">{{ __('Volver al listado') }}</button>
            @endif
        </div>

        @if ($flash)
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ $flash }}</div>
        @endif
        @if ($flashError)
            <div class="wt-errors"><ul style="margin:0;padding:0"><li>{{ $flashError }}</li></ul></div>
        @endif

        @if (! $channel)
            <div class="card card-p">{{ __('Configura primero un canal de WhatsApp (con su WABA ID) en «Canales sociales».') }}</div>
        @elseif ($view === 'list')
            {{-- ============================ LISTADO ============================ --}}
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
                @if ($channels->count() > 1)
                    <select wire:model.live="channelId" style="max-width:240px">
                        @foreach ($channels as $ch)
                            <option value="{{ $ch->id }}">{{ $ch->display_name }}</option>
                        @endforeach
                    </select>
                @endif
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Buscar por nombre…') }}" style="max-width:240px">
                <select wire:model.live="filter" style="max-width:190px">
                    <option value="all">{{ __('Todas') }}</option>
                    <option value="approved">{{ __('Aprobadas') }}</option>
                    <option value="review">{{ __('En revisión') }}</option>
                    <option value="rejected">{{ __('Rechazadas') }}</option>
                    <option value="paused">{{ __('Pausadas') }}</option>
                    <option value="archived">{{ __('Archivadas') }}</option>
                </select>
            </div>

            @forelse ($templates as $tpl)
                <button type="button" class="wt-row" wire:click="show({{ $tpl->id }})" wire:key="wt-{{ $tpl->id }}">
                    <span>
                        <span class="wt-row__name">{{ $tpl->name }}</span>
                        <span class="wt-row__meta">
                            <span class="wt-badge {{ $badge($tpl->status) }}">{{ $tpl->status }}</span>
                            <span class="wt-badge">{{ $tpl->category }}</span>
                            <span class="wt-badge">{{ $tpl->language }}</span>
                            @if ($tpl->quality_score)
                                <span class="wt-badge">{{ __('Calidad') }}: {{ $tpl->quality_score }}</span>
                            @endif
                        </span>
                    </span>
                    <span class="wt-row__sync">
                        {{ $tpl->last_synced_at ? __('Sincronizada :t', ['t' => $tpl->last_synced_at->diffForHumans()]) : '—' }}
                    </span>
                </button>
            @empty
                <div class="card card-p" style="text-align:center;color:#6B7686">
                    {{ __('No hay plantillas todavía. Crea la primera o pulsa «Sincronizar con Meta».') }}
                </div>
            @endforelse
        @elseif ($view === 'create')
            {{-- ============================ DISEÑADOR ============================ --}}
            @if ($builderErrors !== [])
                <div class="wt-errors"><ul style="margin:0;padding:0">
                    @foreach ($builderErrors as $err) <li>{{ $err }}</li> @endforeach
                </ul></div>
            @endif

            <div class="wt-grid">
                <div>
                    <div class="card card-p" style="margin-bottom:16px">
                        <h3 style="margin:0 0 12px;font-size:14px;font-weight:700">{{ __('Configuración') }}</h3>
                        <div class="field">
                            <label>{{ __('Nombre (minúsculas, a-z, 0-9 y _)') }}</label>
                            <input type="text" wire:model.live.debounce.300ms="name" placeholder="confirmacion_solicitud">
                        </div>
                        <div class="field">
                            <label>{{ __('Categoría solicitada') }}</label>
                            <select wire:model.live="category">
                                <option value="UTILITY">UTILITY</option>
                                <option value="MARKETING">MARKETING</option>
                            </select>
                            <p class="mca-help">{{ __('Meta puede recategorizar la plantilla durante la revisión (p. ej. UTILITY → MARKETING). Las plantillas de AUTHENTICATION no se crean desde este diseñador: requieren la estructura OTP específica de Meta y se sincronizan automáticamente si existen.') }}</p>
                        </div>
                        <div class="field">
                            <label>{{ __('Idioma (código de Meta)') }}</label>
                            <input type="text" wire:model.live.debounce.300ms="language" placeholder="es, es_MX, en_US">
                        </div>
                    </div>

                    <div class="card card-p" style="margin-bottom:16px">
                        <h3 style="margin:0 0 12px;font-size:14px;font-weight:700">{{ __('Componentes') }}</h3>
                        <div class="field">
                            <label>{{ __('Encabezado (opcional)') }}</label>
                            <select wire:model.live="headerFormat">
                                <option value="">{{ __('Sin encabezado') }}</option>
                                <option value="TEXT">{{ __('Texto') }}</option>
                                <option value="IMAGE">{{ __('Imagen') }}</option>
                                <option value="VIDEO">{{ __('Video') }}</option>
                                <option value="DOCUMENT">{{ __('Documento') }}</option>
                            </select>
                        </div>
                        @if ($headerFormat === 'TEXT')
                            <div class="field">
                                <label>{{ __('Texto del encabezado (máx. 60)') }}</label>
                                <input type="text" wire:model.live.debounce.300ms="headerText" maxlength="60">
                            </div>
                        @elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                            <div class="field">
                                <label>{{ __('Archivo de EJEMPLO para la revisión de Meta') }}</label>
                                <input type="file" wire:model="headerFile"
                                       accept="{{ $headerFormat === 'IMAGE' ? 'image/jpeg,image/png' : ($headerFormat === 'VIDEO' ? 'video/mp4' : '.pdf') }}">
                                <p class="mca-help">{{ __('Este archivo NO se envía a los contactos: Meta lo usa solo para revisar la plantilla.') }}</p>
                            </div>
                        @endif
                        <div class="field">
                            <label>{{ __('Cuerpo (BODY, obligatorio · máx. 1024)') }}</label>
                            <textarea rows="5" wire:model.live.debounce.300ms="body"
                                      placeholder="Hola {{ $vOpen }}1{{ $vClose }}, hemos recibido tu solicitud para {{ $vOpen }}2{{ $vClose }}."></textarea>
                        </div>
                        <div class="field">
                            <label>{{ __('Pie (FOOTER, opcional · máx. 60 · sin variables)') }}</label>
                            <input type="text" wire:model.live.debounce.300ms="footer" maxlength="60">
                        </div>

                        <label style="font-size:13px;font-weight:600">{{ __('Botones (opcional)') }}</label>
                        <div class="wt-btnrow">
                            <button type="button" class="btn btn-sm" wire:click="addButton('QUICK_REPLY')">+ {{ __('Respuesta rápida') }}</button>
                            <button type="button" class="btn btn-sm" wire:click="addButton('URL')">+ URL</button>
                            <button type="button" class="btn btn-sm" wire:click="addButton('PHONE_NUMBER')">+ {{ __('Teléfono') }}</button>
                        </div>
                        @foreach ($buttons as $i => $btn)
                            <div class="card card-p" style="margin-top:10px;padding:12px 14px" wire:key="btn-{{ $i }}">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span class="wt-badge">{{ $btn['type'] }}</span>
                                    <div class="sp" style="flex:1"></div>
                                    <button type="button" class="btn btn-sm" wire:click="removeButton({{ $i }})">✕</button>
                                </div>
                                <div class="field">
                                    <label>{{ __('Texto del botón (máx. 25)') }}</label>
                                    <input type="text" wire:model.live.debounce.300ms="buttons.{{ $i }}.text" maxlength="25">
                                </div>
                                @if ($btn['type'] === 'URL')
                                    <div class="field">
                                        <label>URL</label>
                                        <input type="url" wire:model.live.debounce.300ms="buttons.{{ $i }}.url" placeholder="https://…">
                                    </div>
                                @elseif ($btn['type'] === 'PHONE_NUMBER')
                                    <div class="field">
                                        <label>{{ __('Número (con código de país)') }}</label>
                                        <input type="text" wire:model.live.debounce.300ms="buttons.{{ $i }}.phone" placeholder="+52 155 5555 5555">
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($variables !== [])
                        <div class="card card-p" style="margin-bottom:16px">
                            <h3 style="margin:0 0 10px;font-size:14px;font-weight:700">{{ __('Variables detectadas') }}</h3>
                            <p class="mca-help" style="margin-top:0">{{ __('Cada variable necesita un valor de ejemplo: Meta lo exige para revisar la plantilla.') }}</p>
                            @foreach ($variables as $n)
                                <div class="field" wire:key="ex-{{ $n }}">
                                    <label><span class="wt-var-chip">{{ $vOpen }}{{ $n }}{{ $vClose }}</span> {{ __('Valor de ejemplo') }}</label>
                                    <input type="text" wire:model.live.debounce.300ms="examples.{{ $n }}" placeholder="{{ $n === 1 ? 'Carlos' : 'MBA' }}">
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="card card-p">
                        <h3 style="margin:0 0 10px;font-size:14px;font-weight:700">{{ __('Resumen antes de enviar') }}</h3>
                        <dl class="wt-summary">
                            <dt>{{ __('Nombre') }}</dt><dd>{{ $name !== '' ? $name : '—' }}</dd>
                            <dt>{{ __('Categoría solicitada') }}</dt><dd>{{ $category }}</dd>
                            <dt>{{ __('Idioma') }}</dt><dd>{{ $language }}</dd>
                            <dt>{{ __('Encabezado') }}</dt><dd>{{ $headerFormat !== '' ? $headerFormat : __('Sin encabezado') }}</dd>
                            <dt>{{ __('Pie') }}</dt><dd>{{ $footer !== '' ? $footer : '—' }}</dd>
                            <dt>{{ __('Botones') }}</dt><dd>{{ count($buttons) }}</dd>
                            <dt>{{ __('Variables') }}</dt><dd>{{ count($variables) }}</dd>
                        </dl>
                        <div style="margin-top:14px">
                            <button type="button" class="btn btn-primary" wire:click="submit"
                                    wire:loading.attr="disabled" wire:target="submit">
                                <span wire:loading.remove wire:target="submit">{{ __('Enviar a revisión de WhatsApp') }}</span>
                                <span wire:loading wire:target="submit">{{ __('Enviando…') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- -------- vista previa tipo teléfono (burbuja limpia, sin marcas) -------- --}}
                <div class="wt-phone">
                    <div class="wt-phone__bubble">
                        @if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                            <div class="wt-phone__media">{{ $headerFormat }}</div>
                        @elseif ($headerFormat === 'TEXT' && trim($headerText) !== '')
                            <div class="wt-phone__header">{{ preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($examples[(int) $m[1]] ?? '')) !== '' ? $examples[(int) $m[1]] : $m[0], $headerText) }}</div>
                        @endif
                        <div style="white-space:pre-wrap">{{ trim($body) !== '' ? preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($examples[(int) $m[1]] ?? '')) !== '' ? $examples[(int) $m[1]] : $m[0], $body) : __('Escribe el cuerpo para ver la vista previa…') }}</div>
                        @if (trim($footer) !== '')
                            <div class="wt-phone__footer">{{ $footer }}</div>
                        @endif
                        @foreach ($buttons as $btn)
                            @if (trim($btn['text']) !== '')
                                <div class="wt-phone__btn">{{ $btn['text'] }}</div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @elseif ($view === 'detail' && $detail)
            {{-- ============================ DETALLE ============================ --}}
            <div class="wt-grid">
                <div class="card card-p">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                        <h3 style="margin:0;font-size:16px;font-weight:700">{{ $detail->name }}</h3>
                        <span class="wt-badge {{ $badge($detail->status) }}">{{ $detail->status }}</span>
                        <span class="wt-badge">{{ $detail->category }}</span>
                        <span class="wt-badge">{{ $detail->language }}</span>
                        @if ($detail->quality_score)
                            <span class="wt-badge">{{ __('Calidad') }}: {{ $detail->quality_score }}</span>
                        @endif
                    </div>

                    @if ($detail->status === 'REJECTED' && $detail->rejection_reason)
                        <div class="wt-errors">
                            <strong style="font-size:12.5px;color:#8A1C1C">{{ __('Motivo de rechazo') }}:</strong>
                            <span style="font-size:12.5px;color:#8A1C1C">{{ $detail->rejection_reason }}</span>
                        </div>
                    @endif

                    <dl class="wt-summary">
                        <dt>{{ __('Última sincronización') }}</dt>
                        <dd>{{ $detail->last_synced_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt>{{ __('ID en Meta') }}</dt>
                        <dd>{{ $detail->meta_template_id ?? '—' }}</dd>
                    </dl>

                    {{-- Sin archivado local: los estados ARCHIVED/DELETED/PENDING_DELETION
                         solo entran desde Meta (sync/webhook). --}}
                    <div style="display:flex;gap:10px;margin-top:16px">
                        <button type="button" class="btn btn-sm" wire:click="refreshTemplate">{{ __('Actualizar desde Meta') }}</button>
                    </div>
                </div>

                <div class="wt-phone">
                    <div class="wt-phone__bubble">
                        @php $dHeader = $detail->component('HEADER'); @endphp
                        @if ($dHeader !== null && in_array(strtoupper((string) ($dHeader['format'] ?? 'TEXT')), ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                            <div class="wt-phone__media">{{ strtoupper((string) $dHeader['format']) }}</div>
                        @elseif ($dHeader !== null && trim((string) ($dHeader['text'] ?? '')) !== '')
                            <div class="wt-phone__header">{{ $dHeader['text'] }}</div>
                        @endif
                        <div style="white-space:pre-wrap">{{ $detail->component('BODY')['text'] ?? '' }}</div>
                        @if (trim((string) ($detail->component('FOOTER')['text'] ?? '')) !== '')
                            <div class="wt-phone__footer">{{ $detail->component('FOOTER')['text'] }}</div>
                        @endif
                        @foreach ((array) ($detail->component('BUTTONS')['buttons'] ?? []) as $btn)
                            @if (is_array($btn) && trim((string) ($btn['text'] ?? '')) !== '')
                                <div class="wt-phone__btn">{{ $btn['text'] }}</div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
