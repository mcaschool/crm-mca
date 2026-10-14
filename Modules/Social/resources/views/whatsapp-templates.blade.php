<div class="mca-page">
    @php
        // Llaves dobles construidas con chr(): un "{{" literal rompería el compilador Blade.
        $vOpen = chr(123).chr(123);
        $vClose = chr(125).chr(125);
        // Estado de Meta → tono de la insignia (el color solo comunica el estado).
        $tone = function (string $status): string {
            return match ($status) {
                'APPROVED' => 'success',
                'PENDING', 'IN_APPEAL' => 'warning',
                'REJECTED' => 'danger',
                'PAUSED', 'DISABLED', 'FLAGGED', 'LOCKED', 'LIMIT_EXCEEDED' => 'deep',
                default => 'neutral',
            };
        };
        $mediaIcon = ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'file-text'];
        $btnIcon = ['URL' => 'external-link', 'PHONE_NUMBER' => 'phone'];
    @endphp

    <x-ui.page-header class="ui-ph-stack" :title="__('Plantillas de WhatsApp')" :subtitle="__('Crea y sincroniza las plantillas de mensaje aprobadas por WhatsApp. Solo las plantillas aprobadas pueden enviarse fuera de la ventana de 24 horas.')">
        @if ($view === 'list' && $channel)
            <x-slot:actions>
                <button type="button" wire:click="sync" class="ui-btn ui-btn--secondary" wire:loading.attr="disabled" wire:target="sync">
                    <x-ui.icon name="refresh" />
                    <span wire:loading.remove wire:target="sync">{{ __('Sincronizar con Meta') }}</span>
                    <span wire:loading wire:target="sync">{{ __('Sincronizando…') }}</span>
                </button>
                @can('create', \Modules\Social\Models\SocialChannel::class)
                    <x-ui.button variant="primary" icon="plus" wire:click="startCreate">{{ __('Crear plantilla') }}</x-ui.button>
                @endcan
            </x-slot:actions>
        @elseif ($view !== 'list')
            <x-slot:actions>
                <x-ui.button icon="chevron-left" wire:click="back">{{ __('Volver al listado') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="ui-stack">
        @if ($flash)
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ $flash }}</div>
        @endif
        @if ($flashError)
            <div class="ui-alert ui-alert--flush ui-tone--danger" role="alert"><x-ui.icon name="alert-triangle" /> {{ $flashError }}</div>
        @endif

        @if (! $channel)
            <div class="ui-card ui-empty">
                <div class="ui-empty-ic"><x-ui.icon name="message-circle" /></div>
                {{ __('Configura primero un canal de WhatsApp (con su WABA ID) en «Canales sociales».') }}
            </div>
        @elseif ($view === 'list')
            {{-- ============================ LISTADO ============================ --}}
            <div class="ui-card ui-card--list">
                <div class="ui-toolbar ui-toolbar--stack">
                    <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Buscar por nombre…')" :aria-label="__('Buscar por nombre…')" />
                    @if ($channels->count() > 1)
                        <select wire:model.live="channelId" class="ui-select" aria-label="{{ __('Canal') }}">
                            @foreach ($channels as $ch)
                                <option value="{{ $ch->id }}">{{ $ch->display_name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <x-ui.select filter :active="$filter !== 'all'" wire:model.live="filter" :aria-label="__('Estado')">
                        <option value="all">{{ __('Todas') }}</option>
                        <option value="approved">{{ __('Aprobadas') }}</option>
                        <option value="review">{{ __('En revisión') }}</option>
                        <option value="rejected">{{ __('Rechazadas') }}</option>
                        <option value="paused">{{ __('Pausadas') }}</option>
                        <option value="archived">{{ __('Archivadas') }}</option>
                    </x-ui.select>
                </div>

                <ul class="ui-list">
                    @forelse ($templates as $tpl)
                        <li wire:key="wt-{{ $tpl->id }}">
                            <button type="button" class="ui-list-item ui-list-item--wrap" wire:click="show({{ $tpl->id }})">
                                <span class="ui-list-main">
                                    <span class="ui-list-title ui-mono">{{ $tpl->name }}</span>
                                    <span class="ui-person-line">
                                        <x-ui.badge :tone="$tone($tpl->status)" dot>{{ $tpl->status }}</x-ui.badge>
                                        <x-ui.badge>{{ $tpl->category }}</x-ui.badge>
                                        <x-ui.badge>{{ $tpl->language }}</x-ui.badge>
                                        @if ($tpl->quality_score)
                                            <x-ui.badge>{{ __('Calidad') }}: {{ $tpl->quality_score }}</x-ui.badge>
                                        @endif
                                    </span>
                                </span>
                                <span class="ui-list-aside ui-list-meta">
                                    {{ $tpl->last_synced_at ? __('Sincronizada :t', ['t' => $tpl->last_synced_at->diffForHumans()]) : '—' }}
                                    <x-ui.icon name="chevron-right" class="ui-list-go" />
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="ui-empty">
                            <div class="ui-empty-ic"><x-ui.icon name="file-text" /></div>
                            {{ __('No hay plantillas todavía. Crea la primera o pulsa «Sincronizar con Meta».') }}
                        </li>
                    @endforelse
                </ul>
            </div>
        @elseif ($view === 'create')
            {{-- ============================ DISEÑADOR ============================ --}}
            @if ($builderErrors !== [])
                <div class="ui-callout ui-callout--stack ui-tone--danger" role="alert">
                    <b><x-ui.icon name="alert-triangle" /> {{ __('Revisa la plantilla antes de enviarla') }}</b>
                    <ul class="ui-callout-list">
                        @foreach ($builderErrors as $err) <li>{{ $err }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <div class="ui-pub">
                <div class="ui-stack">
                    <section class="ui-card" aria-labelledby="wt-cfg-title">
                        <div class="ui-card-h"><x-ui.icon name="file-text" class="ui-card-ic" /><h2 id="wt-cfg-title">{{ __('Configuración') }}</h2></div>
                        <div class="ui-card-p ui-form-grid">
                            <x-ui.field class="ui-span-full" :label="__('Nombre (minúsculas, a-z, 0-9 y _)')" for="wt-name">
                                <input id="wt-name" type="text" wire:model.live.debounce.300ms="name" placeholder="confirmacion_solicitud" class="ui-input ui-mono" autocapitalize="off" spellcheck="false">
                            </x-ui.field>
                            <x-ui.field :label="__('Categoría solicitada')" for="wt-cat">
                                <select id="wt-cat" wire:model.live="category" class="ui-select">
                                    <option value="UTILITY">UTILITY</option>
                                    <option value="MARKETING">MARKETING</option>
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('Idioma (código de Meta)')" for="wt-lang">
                                <input id="wt-lang" type="text" wire:model.live.debounce.300ms="language" placeholder="es, es_MX, en_US" class="ui-input">
                            </x-ui.field>
                            <p class="ui-help ui-span-full">{{ __('Meta puede recategorizar la plantilla durante la revisión (p. ej. UTILITY → MARKETING). Las plantillas de AUTHENTICATION no se crean desde este diseñador: requieren la estructura OTP específica de Meta y se sincronizan automáticamente si existen.') }}</p>
                        </div>
                    </section>

                    <section class="ui-card" aria-labelledby="wt-cmp-title">
                        <div class="ui-card-h"><x-ui.icon name="layers" class="ui-card-ic" /><h2 id="wt-cmp-title">{{ __('Componentes') }}</h2></div>
                        <div class="ui-card-p ui-form-grid">
                            <x-ui.field :label="__('Encabezado (opcional)')" for="wt-hfmt">
                                <select id="wt-hfmt" wire:model.live="headerFormat" class="ui-select">
                                    <option value="">{{ __('Sin encabezado') }}</option>
                                    <option value="TEXT">{{ __('Texto') }}</option>
                                    <option value="IMAGE">{{ __('Imagen') }}</option>
                                    <option value="VIDEO">{{ __('Video') }}</option>
                                    <option value="DOCUMENT">{{ __('Documento') }}</option>
                                </select>
                            </x-ui.field>
                            @if ($headerFormat === 'TEXT')
                                <x-ui.field :label="__('Texto del encabezado (máx. 60)')" for="wt-htext">
                                    <input id="wt-htext" type="text" wire:model.live.debounce.300ms="headerText" maxlength="60" class="ui-input">
                                </x-ui.field>
                            @elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                                <x-ui.field :label="__('Archivo de EJEMPLO para la revisión de Meta')" for="wt-hfile" :help="__('Este archivo NO se envía a los contactos: Meta lo usa solo para revisar la plantilla.')">
                                    <input id="wt-hfile" type="file" wire:model="headerFile" class="ui-input"
                                           accept="{{ $headerFormat === 'IMAGE' ? 'image/jpeg,image/png' : ($headerFormat === 'VIDEO' ? 'video/mp4' : '.pdf') }}">
                                </x-ui.field>
                            @endif
                            <x-ui.field class="ui-span-full" :label="__('Cuerpo (BODY, obligatorio · máx. 1024)')" for="wt-body">
                                <textarea id="wt-body" rows="5" wire:model.live.debounce.300ms="body" class="ui-input ui-textarea"
                                          placeholder="Hola {{ $vOpen }}1{{ $vClose }}, hemos recibido tu solicitud para {{ $vOpen }}2{{ $vClose }}."></textarea>
                            </x-ui.field>
                            <x-ui.field class="ui-span-full" :label="__('Pie (FOOTER, opcional · máx. 60 · sin variables)')" for="wt-footer">
                                <input id="wt-footer" type="text" wire:model.live.debounce.300ms="footer" maxlength="60" class="ui-input">
                            </x-ui.field>

                            <div class="ui-field ui-span-full">
                                <span class="ui-label" id="wt-btns-l">{{ __('Botones (opcional)') }}</span>
                                <div class="ui-actions-row" role="group" aria-labelledby="wt-btns-l">
                                    <x-ui.button size="sm" icon="plus" wire:click="addButton('QUICK_REPLY')">{{ __('Respuesta rápida') }}</x-ui.button>
                                    <x-ui.button size="sm" icon="plus" wire:click="addButton('URL')">URL</x-ui.button>
                                    <x-ui.button size="sm" icon="plus" wire:click="addButton('PHONE_NUMBER')">{{ __('Teléfono') }}</x-ui.button>
                                </div>
                                @if ($buttons !== [])
                                    <ul class="ui-list ui-list--boxed">
                                        @foreach ($buttons as $i => $btn)
                                            <li class="ui-list-item ui-list-item--col" wire:key="btn-{{ $i }}">
                                                <div class="ui-split">
                                                    <x-ui.badge tone="blue">{{ $btn['type'] }}</x-ui.badge>
                                                    <x-ui.button size="sm" variant="ghost" icon="x" wire:click="removeButton({{ $i }})" :aria-label="__('Quitar botón')" />
                                                </div>
                                                <div class="ui-form-grid">
                                                    <x-ui.field :label="__('Texto del botón (máx. 25)')" for="wt-btn-{{ $i }}-text">
                                                        <input id="wt-btn-{{ $i }}-text" type="text" wire:model.live.debounce.300ms="buttons.{{ $i }}.text" maxlength="25" class="ui-input">
                                                    </x-ui.field>
                                                    @if ($btn['type'] === 'URL')
                                                        <x-ui.field label="URL" for="wt-btn-{{ $i }}-url">
                                                            <input id="wt-btn-{{ $i }}-url" type="url" wire:model.live.debounce.300ms="buttons.{{ $i }}.url" placeholder="https://…" class="ui-input">
                                                        </x-ui.field>
                                                    @elseif ($btn['type'] === 'PHONE_NUMBER')
                                                        <x-ui.field :label="__('Número (con código de país)')" for="wt-btn-{{ $i }}-phone">
                                                            <input id="wt-btn-{{ $i }}-phone" type="text" wire:model.live.debounce.300ms="buttons.{{ $i }}.phone" placeholder="+52 155 5555 5555" class="ui-input">
                                                        </x-ui.field>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </section>

                    @if ($variables !== [])
                        <section class="ui-card" aria-labelledby="wt-var-title">
                            <div class="ui-card-h"><x-ui.icon name="sticky-note" class="ui-card-ic" /><h2 id="wt-var-title">{{ __('Variables detectadas') }}</h2></div>
                            <div class="ui-card-p ui-stack">
                                <p class="ui-help">{{ __('Cada variable necesita un valor de ejemplo: Meta lo exige para revisar la plantilla.') }}</p>
                                <div class="ui-form-grid">
                                    @foreach ($variables as $n)
                                        <div class="ui-field" wire:key="ex-{{ $n }}">
                                            <label class="ui-label ui-person-line" for="wt-ex-{{ $n }}"><span class="ui-tpl-vars"><code>{{ $vOpen }}{{ $n }}{{ $vClose }}</code></span> {{ __('Valor de ejemplo') }}</label>
                                            <input id="wt-ex-{{ $n }}" type="text" wire:model.live.debounce.300ms="examples.{{ $n }}" placeholder="{{ $n === 1 ? 'Carlos' : 'MBA' }}" class="ui-input">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </section>
                    @endif
                </div>

                <aside class="ui-pub-side">
                    {{-- -------- vista previa (burbuja limpia, sin marcas) -------- --}}
                    <section class="ui-card" aria-labelledby="wt-prev-title">
                        <div class="ui-card-h"><x-ui.icon name="eye" class="ui-card-ic" /><h2 id="wt-prev-title">{{ __('Vista previa') }}</h2></div>
                        <div class="ui-card-p">
                            <div class="ui-msg-preview">
                                <div class="ui-bubble ui-bubble--in">
                                    @if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                                        <span class="ui-bubble-doc is-pending"><x-ui.icon :name="$mediaIcon[$headerFormat]" /><span>{{ $headerFormat }}</span></span>
                                    @elseif ($headerFormat === 'TEXT' && trim($headerText) !== '')
                                        <div class="ui-bubble-head">{{ preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($examples[(int) $m[1]] ?? '')) !== '' ? $examples[(int) $m[1]] : $m[0], $headerText) }}</div>
                                    @endif
                                    @if (trim($body) !== '')
                                        <div class="ui-bubble-text">{{ preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($examples[(int) $m[1]] ?? '')) !== '' ? $examples[(int) $m[1]] : $m[0], $body) }}</div>
                                    @else
                                        <div class="ui-bubble-text ui-bubble-foot">{{ __('Escribe el cuerpo para ver la vista previa…') }}</div>
                                    @endif
                                    @if (trim($footer) !== '')
                                        <div class="ui-bubble-foot">{{ $footer }}</div>
                                    @endif
                                    @if (collect($buttons)->contains(fn ($b) => trim($b['text']) !== ''))
                                        <div class="ui-bubble-btns">
                                            @foreach ($buttons as $btn)
                                                @if (trim($btn['text']) !== '')
                                                    <div class="ui-bubble-btn">@isset($btnIcon[$btn['type']])<x-ui.icon :name="$btnIcon[$btn['type']]" />@endisset{{ $btn['text'] }}</div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ui-card" aria-labelledby="wt-sum-title">
                        <div class="ui-card-h"><x-ui.icon name="check" class="ui-card-ic" /><h2 id="wt-sum-title">{{ __('Resumen antes de enviar') }}</h2></div>
                        <div class="ui-card-p">
                            <dl class="ui-meta">
                                <dt>{{ __('Nombre') }}</dt><dd>@if ($name !== '')<span class="ui-mono">{{ $name }}</span>@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Categoría solicitada') }}</dt><dd>{{ $category }}</dd>
                                <dt>{{ __('Idioma') }}</dt><dd>{{ $language }}</dd>
                                <dt>{{ __('Encabezado') }}</dt><dd>{{ $headerFormat !== '' ? $headerFormat : __('Sin encabezado') }}</dd>
                                <dt>{{ __('Pie') }}</dt><dd>{{ $footer !== '' ? $footer : '—' }}</dd>
                                <dt>{{ __('Botones') }}</dt><dd>{{ count($buttons) }}</dd>
                                <dt>{{ __('Variables') }}</dt><dd>{{ count($variables) }}</dd>
                            </dl>
                        </div>
                        <div class="ui-card-f">
                            <button type="button" class="ui-btn ui-btn--primary ui-pub-go" wire:click="submit"
                                    wire:loading.attr="disabled" wire:target="submit">
                                <x-ui.icon name="send" />
                                <span wire:loading.remove wire:target="submit">{{ __('Enviar a revisión de WhatsApp') }}</span>
                                <span wire:loading wire:target="submit">{{ __('Enviando…') }}</span>
                            </button>
                        </div>
                    </section>
                </aside>
            </div>
        @elseif ($view === 'detail' && $detail)
            {{-- ============================ DETALLE ============================ --}}
            <div class="ui-pub">
                <section class="ui-card" aria-labelledby="wt-det-title">
                    <div class="ui-card-h">
                        <x-ui.icon name="file-text" class="ui-card-ic" />
                        <h2 id="wt-det-title" class="ui-mono">{{ $detail->name }}</h2>
                        <x-ui.badge :tone="$tone($detail->status)" dot>{{ $detail->status }}</x-ui.badge>
                        <x-ui.badge>{{ $detail->category }}</x-ui.badge>
                        <x-ui.badge>{{ $detail->language }}</x-ui.badge>
                        @if ($detail->quality_score)
                            <x-ui.badge>{{ __('Calidad') }}: {{ $detail->quality_score }}</x-ui.badge>
                        @endif
                    </div>
                    <div class="ui-card-p ui-stack">
                        @if ($detail->status === 'REJECTED' && $detail->rejection_reason)
                            <div class="ui-callout ui-tone--danger" role="status">
                                <x-ui.icon name="alert-triangle" />
                                <span><b>{{ __('Motivo de rechazo') }}:</b> {{ $detail->rejection_reason }}</span>
                            </div>
                        @endif

                        <dl class="ui-meta">
                            <dt>{{ __('Última sincronización') }}</dt>
                            <dd>{{ $detail->last_synced_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            <dt>{{ __('ID en Meta') }}</dt>
                            <dd>@if ($detail->meta_template_id)<span class="ui-mono">{{ $detail->meta_template_id }}</span>@else<span class="t-nil">—</span>@endif</dd>
                        </dl>
                    </div>
                    {{-- Sin archivado local: los estados ARCHIVED/DELETED/PENDING_DELETION
                         solo entran desde Meta (sync/webhook). --}}
                    <div class="ui-card-f">
                        <x-ui.button size="sm" icon="refresh" wire:click="refreshTemplate">{{ __('Actualizar desde Meta') }}</x-ui.button>
                    </div>
                </section>

                <aside class="ui-pub-side">
                    <section class="ui-card" aria-labelledby="wt-dprev-title">
                        <div class="ui-card-h"><x-ui.icon name="eye" class="ui-card-ic" /><h2 id="wt-dprev-title">{{ __('Vista previa') }}</h2></div>
                        <div class="ui-card-p">
                            <div class="ui-msg-preview">
                                <div class="ui-bubble ui-bubble--in">
                                    @php
                                        $dHeader = $detail->component('HEADER');
                                        $dFormat = $dHeader !== null ? strtoupper((string) ($dHeader['format'] ?? 'TEXT')) : null;
                                        $dButtons = collect((array) ($detail->component('BUTTONS')['buttons'] ?? []))->filter(fn ($b) => is_array($b) && trim((string) ($b['text'] ?? '')) !== '');
                                    @endphp
                                    @if ($dHeader !== null && in_array($dFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                                        <span class="ui-bubble-doc is-pending"><x-ui.icon :name="$mediaIcon[$dFormat]" /><span>{{ $dFormat }}</span></span>
                                    @elseif ($dHeader !== null && trim((string) ($dHeader['text'] ?? '')) !== '')
                                        <div class="ui-bubble-head">{{ $dHeader['text'] }}</div>
                                    @endif
                                    <div class="ui-bubble-text">{{ $detail->component('BODY')['text'] ?? '' }}</div>
                                    @if (trim((string) ($detail->component('FOOTER')['text'] ?? '')) !== '')
                                        <div class="ui-bubble-foot">{{ $detail->component('FOOTER')['text'] }}</div>
                                    @endif
                                    @if ($dButtons->isNotEmpty())
                                        <div class="ui-bubble-btns">
                                            @foreach ($dButtons as $btn)
                                                <div class="ui-bubble-btn">@isset($btnIcon[strtoupper((string) ($btn['type'] ?? ''))])<x-ui.icon :name="$btnIcon[strtoupper((string) $btn['type'])]" />@endisset{{ $btn['text'] }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        @endif
    </div>
</div>
