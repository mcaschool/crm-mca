@php
    use Illuminate\Support\Str;
    use Modules\Ai\Support\KnowledgeTaxonomy;

    // Líneas presentes para chips ('' = sin línea → clave sin_categoria). Las de la lista fija
    // usan su etiqueta; un valor antiguo fuera de la lista se muestra tal cual.
    $kcCats = collect($byCategory)
        ->map(fn ($count, $key) => [
            'key' => ($key === '' || $key === null) ? 'sin_categoria' : (string) $key,
            'label' => ($key === '' || $key === null) ? __('Sin línea') : __((string) KnowledgeTaxonomy::lineLabel((string) $key)),
            'count' => (int) $count,
        ])
        ->sortBy(fn ($c) => $c['key'] === 'sin_categoria' ? 1 : 0)
        ->values();
    $kcNoCat = (int) ($kcCats->firstWhere('key', 'sin_categoria')['count'] ?? 0);
    // Opciones del filtro de línea: la lista fija + valores antiguos que existan fuera de ella.
    $kcLineOptions = collect($lines)->map(fn ($l) => __($l))
        ->union($kcCats->reject(fn ($c) => $c['key'] === 'sin_categoria' || isset($lines[$c['key']]))->pluck('label', 'key'))
        ->put('sin_categoria', __('Sin línea'));

    // Color de avatar estable por agente (hash del nombre sobre una paleta fija de tokens v4).
    $kcPalette = [
        ['bg' => 'var(--mca-gold-soft)', 'fg' => 'var(--mca-gold)'],
        ['bg' => 'var(--mca-blue-soft)', 'fg' => 'var(--mca-blue)'],
        ['bg' => 'var(--mca-ok-soft)', 'fg' => 'var(--mca-ok)'],
        ['bg' => 'var(--mca-warn-soft)', 'fg' => 'var(--mca-warn)'],
        ['bg' => '#F1EEF9', 'fg' => '#6D5AB8'],
    ];
    $kcAvatar = fn (string $name): array => $kcPalette[abs(crc32($name)) % count($kcPalette)];
@endphp

<div>
    <x-ui.styles />
    @include('ai::livewire.knowledge._styles')

    <div class="mca-panel kc">
        {{-- Cabecera --}}
        <div class="kc-header">
            <div>
                <h1 class="kc-title">{{ __('Centro de Conocimiento') }}</h1>
                <p class="kc-sub">{{ __('Biblioteca central de fuentes, compartida entre todos los agentes.') }}</p>
            </div>
            <button type="button" wire:click="syncLibrary" wire:loading.attr="disabled" wire:target="syncLibrary" class="kc-btn kc-btn-ghost">
                <span wire:loading.remove wire:target="syncLibrary" class="kc-inl"><x-ui.icon name="refresh" /> {{ __('Sincronizar biblioteca') }}</span>
                <span wire:loading.inline-flex wire:target="syncLibrary" class="kc-gap"><span class="mca-spin"></span> {{ __('Sincronizando…') }}</span>
            </button>
        </div>

        @include('ai::livewire.knowledge._tabs')

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- 1) Tarjetas de resumen (clic = filtrar) --}}
        <div class="kc-stats">
            <button type="button" wire:click="clearFilters" @class(['kc-stat', 'on' => $search === '' && $filterCategory === '' && $filterStatus === '' && $filterType === ''])>
                <div class="kc-stat-top">
                    <span class="kc-stat-label">{{ __('Total de fuentes') }}</span>
                    <span class="kc-ic t-blue"><x-ui.icon name="book-open" /></span>
                </div>
                <div class="kc-stat-num">{{ $summary['total'] }}</div>
            </button>
            <button type="button" wire:click="$set('filterStatus', 'active')" @class(['kc-stat', 'on' => $filterStatus === 'active'])>
                <div class="kc-stat-top">
                    <span class="kc-stat-label">{{ __('Activas') }}</span>
                    <span class="kc-ic t-green"><x-ui.icon name="check" /></span>
                </div>
                <div class="kc-stat-num c-green">{{ $summary['active'] }}</div>
            </button>
            <button type="button" wire:click="$set('filterStatus', 'inactive')" @class(['kc-stat', 'on' => $filterStatus === 'inactive'])>
                <div class="kc-stat-top">
                    <span class="kc-stat-label">{{ __('Inactivas') }}</span>
                    <span class="kc-ic t-gray">
                        {{-- Lucide "ban" (círculo tachado) --}}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>
                    </span>
                </div>
                <div class="kc-stat-num c-gray">{{ $summary['inactive'] }}</div>
            </button>
            <button type="button" wire:click="filterByCategory('sin_categoria')" @class(['kc-stat', 'on' => $filterCategory === 'sin_categoria'])>
                <div class="kc-stat-top">
                    <span class="kc-stat-label">{{ __('Sin línea') }}</span>
                    <span class="kc-ic t-amber"><x-ui.icon name="alert-triangle" /></span>
                </div>
                <div class="kc-stat-num c-amber">{{ $kcNoCat }}</div>
            </button>
        </div>

        {{-- 2) Chips de línea --}}
        <div class="kc-chips">
            <span class="kc-chips-label">{{ __('Filtrar por línea:') }}</span>
            <button type="button" wire:click="filterByCategory('')" @class(['kc-chip', 'on' => $filterCategory === ''])>{{ __('Todas') }} · {{ $summary['total'] }}</button>
            @foreach ($kcCats as $c)
                <button type="button" wire:key="chip-{{ $c['key'] }}" wire:click="filterByCategory('{{ $c['key'] }}')" @class(['kc-chip', 'on' => $filterCategory === $c['key']])>{{ $c['label'] }} · {{ $c['count'] }}</button>
            @endforeach
        </div>

        {{-- 3) Espacios de subida: Programa Académico | Base de Conocimiento --}}
        <div class="kc-spaces">
            {{-- Programa Académico: línea (sin la institucional) + programa del catálogo --}}
            <div class="kc-card kc-space">
                <div class="kc-space-head">
                    <div class="kc-upload-ic"><x-ui.icon name="book-open" /></div>
                    <div class="kc-upload-text">
                        <h3>{{ __('Programa Académico') }}</h3>
                        <p>{{ __('Contenido de un programa concreto del catálogo.') }}</p>
                    </div>
                </div>

                <div class="kc-field">
                    <span class="kc-field-label">{{ __('Línea') }}</span>
                    @include('ai::livewire.knowledge._dropdown', [
                        'model' => 'programLine', 'live' => false, 'field' => true,
                        'options' => collect($programLines)->map(fn ($l) => __($l))->all(),
                        'placeholder' => __('Elige la línea…'), 'aria' => __('Línea del programa'),
                    ])
                    @error('programLine') <span class="kc-err">{{ $message }}</span> @enderror
                </div>

                {{-- Carga masiva por línea: el programa sale de cada archivo --}}
                <button type="button" wire:click="$toggle('programAuto')" class="kc-switch-btn kc-auto"
                        role="switch" aria-checked="{{ $programAuto ? 'true' : 'false' }}">
                    <span @class(['kc-switch', 'on' => $programAuto])></span>
                    {{ __('Asignar programa automáticamente desde cada archivo') }}
                </button>

                @if ($programAuto)
                    <p class="kc-auto-help">{!! __('Cada archivo indica su programa con <code>Programa: CÓDIGO</code> en el comentario de metadatos; si no lo trae, se busca por la URL de la ficha del programa. Los archivos que no se puedan asignar se rechazan con el motivo y el resto se sube.') !!}</p>
                @else
                    <div class="kc-field">
                        <span class="kc-field-label">{{ __('Programa del catálogo') }}</span>
                        <div class="kc-field-search">
                            <x-ui.icon name="search" />
                            <input type="text" wire:model.live.debounce.300ms="programSearch" placeholder="{{ __('Buscar por nombre o código…') }}" aria-label="{{ __('Buscar programa') }}">
                        </div>
                        @include('ai::livewire.knowledge._dropdown', [
                            'model' => 'programId', 'live' => false, 'field' => true,
                            'options' => $programs->mapWithKeys(fn ($p) => [$p->id => ($p->code ? $p->code.' · ' : '').$p->name_es])->all(),
                            'placeholder' => __('Elige un programa…'), 'aria' => __('Programa del catálogo'),
                        ])
                        @error('programId') <span class="kc-err">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div class="kc-space-foot">
                    <div wire:loading wire:target="programDocs" class="kc-meta"><span class="mca-spin"></span> {{ __('Cargando archivos…') }}</div>
                    @error('programDocs') <div class="kc-err">{{ $message }}</div> @enderror
                    @if (! empty($programDocs))
                        <div class="kc-picked-files">
                            @foreach ($programDocs as $d)
                                <span class="kc-file-tag"><x-ui.icon name="file-text" /> {{ method_exists($d, 'getClientOriginalName') ? $d->getClientOriginalName() : '' }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="kc-space-actions">
                        <label class="kc-btn kc-btn-ghost">
                            <x-ui.icon name="upload" /> {{ __('Elegir archivos') }}
                            <input type="file" wire:model="programDocs" accept=".md" multiple class="kc-file">
                        </label>
                        <button type="button" wire:click="uploadProgramDocs" wire:loading.attr="disabled" wire:target="uploadProgramDocs,programDocs" class="kc-btn kc-btn-primary">
                            <span wire:loading.remove wire:target="uploadProgramDocs" class="kc-inl"><x-ui.icon name="check" /> {{ __('Subir') }}</span>
                            <span wire:loading.inline-flex wire:target="uploadProgramDocs" class="kc-gap"><span class="mca-spin"></span> {{ __('Procesando…') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Base de Conocimiento: solo línea (incluida la institucional), sin programa --}}
            <div class="kc-card kc-space">
                <div class="kc-space-head">
                    <div class="kc-upload-ic t-gold"><x-ui.icon name="layers" /></div>
                    <div class="kc-upload-text">
                        <h3>{{ __('Base de Conocimiento') }}</h3>
                        <p>{{ __('Admisiones, titulaciones y FAQs de toda una línea.') }}</p>
                    </div>
                </div>

                <div class="kc-field">
                    <span class="kc-field-label">{{ __('Línea') }}</span>
                    @include('ai::livewire.knowledge._dropdown', [
                        'model' => 'kbLine', 'live' => false, 'field' => true,
                        'options' => collect($lines)->map(fn ($l) => __($l))->all(),
                        'placeholder' => __('Elige la línea…'), 'aria' => __('Línea de la base de conocimiento'),
                    ])
                    @error('kbLine') <span class="kc-err">{{ $message }}</span> @enderror
                </div>

                <div class="kc-space-foot">
                    <div wire:loading wire:target="docs" class="kc-meta"><span class="mca-spin"></span> {{ __('Cargando archivos…') }}</div>
                    @error('docs') <div class="kc-err">{{ $message }}</div> @enderror
                    @if (! empty($docs))
                        <div class="kc-picked-files">
                            @foreach ($docs as $d)
                                <span class="kc-file-tag"><x-ui.icon name="file-text" /> {{ method_exists($d, 'getClientOriginalName') ? $d->getClientOriginalName() : '' }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="kc-space-actions">
                        <label class="kc-btn kc-btn-ghost">
                            <x-ui.icon name="upload" /> {{ __('Elegir archivos') }}
                            <input type="file" wire:model="docs" accept=".md" multiple class="kc-file">
                        </label>
                        <button type="button" wire:click="uploadDocs" wire:loading.attr="disabled" wire:target="uploadDocs,docs" class="kc-btn kc-btn-primary">
                            <span wire:loading.remove wire:target="uploadDocs" class="kc-inl"><x-ui.icon name="check" /> {{ __('Subir') }}</span>
                            <span wire:loading.inline-flex wire:target="uploadDocs" class="kc-gap"><span class="mca-spin"></span> {{ __('Procesando…') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <p class="kc-upload-note">{{ __('Archivos .md: comentario con «Codigo», título «# » y al menos una sección «## ». Máx. 512 KB por archivo. Si el archivo declara «Categoria», debe coincidir con la línea elegida.') }}</p>

        @if ($uploadResults !== [])
            <div class="kc-card kc-upload">
                <div class="kc-results" style="margin-top:0;padding-top:0;border-top:0">
                    @php $kcCount = collect($uploadResults)->countBy('result'); @endphp
                    <h4>{{ __('Resultado de la subida') }}
                        <span class="kc-report-sum">{{ __(':new nuevos · :upd actualizados · :rej rechazados', ['new' => $kcCount['Nuevo'] ?? 0, 'upd' => $kcCount['Actualizado'] ?? 0, 'rej' => $kcCount['Rechazado'] ?? 0]) }}</span>
                    </h4>
                    <table class="kc-report">
                        <thead>
                            <tr>
                                <th>{{ __('Archivo') }}</th>
                                <th>{{ __('Programa asignado') }}</th>
                                <th>{{ __('Resultado') }}</th>
                                <th>{{ __('Motivo') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($uploadResults as $r)
                                @php $tone = match ($r['result']) { 'Nuevo' => 't-green', 'Actualizado' => 't-blue', default => 't-red' }; @endphp
                                <tr>
                                    <td><span class="kc-code">{{ $r['file'] }}</span></td>
                                    <td>{{ $r['program'] ?? '—' }}</td>
                                    <td><span class="kc-badge {{ $tone }}"><span class="kc-dot"></span>{{ __($r['result']) }}</span></td>
                                    <td class="kc-meta">{{ $r['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- 4) Barra de herramientas --}}
        <div class="kc-toolbar">
            <div class="kc-search">
                <x-ui.icon name="search" />
                <input type="text" wire:model.live.debounce.350ms="search" placeholder="{{ __('Buscar por código o nombre…') }}" aria-label="{{ __('Buscar por código o nombre') }}">
            </div>
            @include('ai::livewire.knowledge._dropdown', [
                'model' => 'filterCategory', 'live' => true, 'emptyOption' => true,
                'options' => $kcLineOptions->all(),
                'placeholder' => __('Todas'), 'label' => __('Línea:'), 'aria' => __('Filtrar por línea'),
            ])
            @include('ai::livewire.knowledge._dropdown', [
                'model' => 'filterType', 'live' => true, 'emptyOption' => true,
                'options' => collect($types)->map(fn ($l) => __($l))->put('sin_tipo', __('Sin tipo'))->all(),
                'placeholder' => __('Todos'), 'label' => __('Tipo:'), 'aria' => __('Filtrar por tipo'),
            ])
            @include('ai::livewire.knowledge._dropdown', [
                'model' => 'filterStatus', 'live' => true, 'emptyOption' => true,
                'options' => ['active' => __('Activas'), 'inactive' => __('Inactivas')],
                'placeholder' => __('Todos'), 'label' => __('Estado:'), 'aria' => __('Filtrar por estado'),
            ])
            @if ($search !== '' || $filterCategory !== '' || $filterStatus !== '' || $filterType !== '')
                <button type="button" wire:click="clearFilters" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="x" /> {{ __('Limpiar') }}</button>
            @endif
        </div>

        {{-- 5) Tabla --}}
        <div class="kc-card kc-table-card">
            <div class="kc-table-scroll">
                <table class="kc-table">
                    <thead>
                        <tr>
                            <th class="kc-fit">{{ __('Código') }}</th>
                            <th class="kc-col-name">{{ __('Nombre') }}</th>
                            <th class="kc-fit">{{ __('Línea / tipo') }}</th>
                            <th class="kc-col-num kc-fit">{{ __('Prior.') }}</th>
                            <th class="kc-col-num kc-fit">{{ __('Secc.') }}</th>
                            <th class="kc-fit">{{ __('Agentes') }}</th>
                            <th class="kc-fit">{{ __('Estado') }}</th>
                            <th class="kc-fit" style="text-align:right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sources as $s)
                            @php $active = $s['status'] === 'active'; @endphp
                            <tr wire:key="ks-{{ $s['id'] }}" @class(['is-off' => ! $active])>
                                <td><span class="kc-code">{{ $s['code'] }}</span></td>
                                <td title="{{ $s['last_synced_at'] ? __('Sincronizado :t', ['t' => $s['last_synced_at']->diffForHumans()]) : __('Sin sincronizar') }}">
                                    <div class="kc-name">{{ $s['name'] }}</div>
                                    @if ($s['program'])
                                        <div @class(['kc-prog', 'gone' => $s['program']['gone']]) title="{{ $s['program']['name'] }}{{ $s['program']['gone'] ? ' — '.__('ya no está activo en el catálogo') : '' }}">
                                            <x-ui.icon name="book-open" /><span>{{ $s['program']['name'] }}</span>
                                        </div>
                                    @endif
                                    <div class="kc-meta kc-meta-num">{{ __('Prior. :p · :n secc.', ['p' => $s['priority'], 'n' => $s['sections']]) }}</div>
                                </td>
                                <td>
                                    @php
                                        $catKnown = KnowledgeTaxonomy::isLine($s['category']);
                                        $catLabel = $s['category'] === null ? null : ($catKnown ? __((string) KnowledgeTaxonomy::lineLabel($s['category'])) : $s['category']);
                                        $typeLabel = KnowledgeTaxonomy::typeLabel($s['type']);
                                        $typeLabel = $typeLabel !== null ? __($typeLabel) : null;
                                    @endphp
                                    @if ($catLabel)
                                        <span @class(['kc-badge', 'kc-cat', 't-blue' => $catKnown, 't-amber' => ! $catKnown]) title="{{ $catKnown ? $catLabel : __(':v — fuera de la lista de líneas', ['v' => $catLabel]) }}">{{ $catLabel }}</span>
                                    @else
                                        <span class="kc-badge t-amber">{{ __('Sin línea') }}</span>
                                    @endif
                                    <div @class(['kc-type', 'unknown' => $typeLabel === null])>{{ $typeLabel ?? __('Sin tipo') }}</div>
                                </td>
                                <td class="kc-num kc-col-num">{{ $s['priority'] }}</td>
                                <td class="kc-col-num"><span class="kc-sec"><x-ui.icon name="book-open" /> {{ $s['sections'] }}</span></td>
                                <td>
                                    @if ($s['agents'] !== [])
                                        <span class="kc-avatars" title="{{ implode(', ', $s['agents']) }}">
                                            @foreach (array_slice($s['agents'], 0, 3) as $agentName)
                                                @php $col = $kcAvatar($agentName); @endphp
                                                <span class="kc-av" style="background:{{ $col['bg'] }};color:{{ $col['fg'] }}">{{ Str::upper(Str::substr($agentName, 0, 1)) }}</span>
                                            @endforeach
                                            @if (count($s['agents']) > 3)
                                                <span class="kc-av kc-av-more">+{{ count($s['agents']) - 3 }}</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="kc-none">— {{ __('Ninguno') }} —</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($active)
                                        <span class="kc-badge t-green"><span class="kc-dot"></span>{{ __('Activa') }}</span>
                                    @else
                                        <span class="kc-badge t-gray"><span class="kc-dot"></span>{{ __('Inactiva') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="kc-actions">
                                        <button type="button" wire:click="view({{ $s['id'] }})" class="kc-icon-btn" title="{{ __('Ver contenido') }}" aria-label="{{ __('Ver contenido') }}"><x-ui.icon name="eye" /></button>
                                        <button type="button" wire:click="toggleStatus({{ $s['id'] }})" @class(['kc-icon-btn', 'go' => ! $active]) title="{{ $active ? __('Desactivar') : __('Activar') }}" aria-label="{{ $active ? __('Desactivar') : __('Activar') }}"><x-ui.icon name="power" /></button>
                                        <button type="button" wire:click="confirmDelete({{ $s['id'] }})" class="kc-icon-btn danger" title="{{ __('Borrar') }}" aria-label="{{ __('Borrar') }}"><x-ui.icon name="trash" /></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="kc-empty">
                                        <span class="kc-ic t-blue"><x-ui.icon name="book-open" /></span>
                                        <p>{{ __('No hay fuentes que coincidan. Sube archivos .md o ajusta los filtros.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kc-foot">{{ __('Mostrando :n de :t fuentes', ['n' => count($sources), 't' => $summary['total']]) }}</div>

        {{-- Drawer de contenido --}}
        @if ($viewingId !== null)
            <div class="kc-overlay" wire:click="closeDrawer"></div>
            <aside class="kc-drawer" aria-label="{{ __('Contenido de la fuente') }}">
                <div class="kc-drawer-head">
                    <span class="kc-ic t-blue"><x-ui.icon name="file-text" /></span>
                    <strong>{{ $viewingName }}</strong>
                    <button type="button" wire:click="closeDrawer" class="kc-icon-btn" aria-label="{{ __('Cerrar') }}"><x-ui.icon name="x" /></button>
                </div>
                <div class="kc-drawer-body">
                    @if ($viewingSections !== [])
                        <nav class="kc-drawer-nav">
                            <h5>{{ __('Secciones') }}</h5>
                            @foreach ($viewingSections as $sec)
                                <div>{{ $sec }}</div>
                            @endforeach
                        </nav>
                    @endif
                    <div class="kc-prose">{!! $viewingHtml !!}</div>
                </div>
            </aside>
        @endif

        {{-- Modal de borrado --}}
        @if ($deletingId !== null)
            <div class="kc-modal-wrap" wire:click="cancelDelete">
                <div class="kc-modal" wire:click.stop role="dialog" aria-modal="true">
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <span class="kc-ic t-red"><x-ui.icon name="alert-triangle" /></span>
                        <div>
                            <h2>{{ __('Borrar fuente de conocimiento') }}</h2>
                            <p>{{ __('Vas a eliminar «:name». Se borrará la fila, sus asignaciones a agentes y su archivo .md. Esta acción no se puede deshacer.', ['name' => $deletingName]) }}</p>
                            @if ($deletingAgents !== [])
                                <div class="kc-modal-note"><strong>{{ __('La usan :n agente(s):', ['n' => count($deletingAgents)]) }}</strong> {{ implode(', ', $deletingAgents) }}</div>
                            @else
                                <p style="margin:0">{{ __('Ningún agente la tiene asignada.') }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="kc-modal-foot">
                        <button type="button" wire:click="cancelDelete" class="kc-btn kc-btn-ghost kc-btn-sm">{{ __('Cancelar') }}</button>
                        <button type="button" wire:click="delete" wire:loading.attr="disabled" class="kc-btn kc-btn-danger kc-btn-sm">
                            <span wire:loading.remove wire:target="delete" class="kc-inl"><x-ui.icon name="trash" /> {{ __('Borrar definitivamente') }}</span>
                            <span wire:loading.inline-flex wire:target="delete" class="kc-gap"><span class="mca-spin"></span> {{ __('Borrando…') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
