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

        @include('ai::livewire.knowledge._tabs', ['active' => 'library'])

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
                    {{-- En vivo: la línea filtra el desplegable de programas al momento --}}
                    @include('ai::livewire.knowledge._dropdown', [
                        'model' => 'programLine', 'live' => true, 'field' => true,
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

                {{-- Alta manual en el catálogo (solo quien puede gestionarlo; el servidor lo vuelve a comprobar) --}}
                @php $kcProgramActions = auth()->user()?->can('create', \Modules\Catalog\Models\Program::class) ?? false; @endphp
                @if ($programAuto)
                    <p class="kc-auto-help">{!! __('Cada archivo indica su programa con <code>Programa: CÓDIGO</code> en el comentario de metadatos; si no lo trae, se busca por la URL de la ficha del programa. Los archivos que no se puedan asignar se rechazan con el motivo y el resto se sube.') !!}</p>
                    @if ($kcProgramActions)
                        <div class="kc-prog-actions">
                            <button type="button" wire:click="openAddProgram" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="plus" /> {{ __('Añadir programa') }}</button>
                            <button type="button" wire:click="openImportPrograms" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="upload" /> {{ __('Importar') }}</button>
                        </div>
                    @endif
                @else
                    <div class="kc-field">
                        <span class="kc-field-label">{{ __('Programa del catálogo') }}</span>
                        <div class="kc-field-search">
                            <x-ui.icon name="search" />
                            <input type="text" wire:model.live.debounce.300ms="programSearch" placeholder="{{ __('Buscar por nombre o código…') }}" aria-label="{{ __('Buscar programa') }}">
                        </div>
                        <div class="kc-prog-pick">
                            @include('ai::livewire.knowledge._dropdown', [
                                'model' => 'programId', 'live' => false, 'field' => true,
                                'options' => $programs->mapWithKeys(fn ($p) => [$p->id => ($p->code ? $p->code.' · ' : '').$p->name_es])->all(),
                                'placeholder' => $programLine === '' ? __('Elige primero la línea…') : __('Elige un programa…'), 'aria' => __('Programa del catálogo'),
                            ])
                            @if ($kcProgramActions)
                                <div class="kc-prog-actions">
                                    <button type="button" wire:click="openAddProgram" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="plus" /> {{ __('Añadir programa') }}</button>
                                    <button type="button" wire:click="openImportPrograms" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="upload" /> {{ __('Importar') }}</button>
                                </div>
                            @endif
                        </div>
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

        {{-- Modal «Añadir programa» (alta individual en el catálogo) --}}
        @if ($showAddProgram)
            <div class="kc-modal-wrap" wire:click="closeAddProgram">
                <div class="kc-modal" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-add-program-title">
                    <h2 id="kc-add-program-title">{{ __('Añadir programa al catálogo') }}</h2>
                    <p>{{ __('Se crea en el catálogo institucional, activo, y queda elegido para subir su ficha.') }}</p>
                    <form wire:submit="createProgram" class="kc-modal-form">
                        <label class="kc-field">
                            <span class="kc-field-label">{{ __('Nombre del programa') }}</span>
                            <input type="text" wire:model="newProgramName" class="kc-input" placeholder="{{ __('Ej.: Micro MBA') }}" maxlength="200" autofocus>
                            @error('newProgramName') <span class="kc-err">{{ $message }}</span> @enderror
                        </label>
                        <label class="kc-field">
                            <span class="kc-field-label">{{ __('Código') }}</span>
                            <input type="text" wire:model="newProgramCode" class="kc-input" placeholder="{{ __('Ej.: MMBA-001') }}" maxlength="40">
                            @error('newProgramCode') <span class="kc-err">{{ $message }}</span> @enderror
                        </label>
                        <div class="kc-field">
                            <span class="kc-field-label">{{ __('Línea') }}</span>
                            @include('ai::livewire.knowledge._dropdown', [
                                'model' => 'newProgramLine', 'live' => true, 'field' => true,
                                'options' => collect($programLines)->map(fn ($l) => __($l))->all(),
                                'placeholder' => __('Elige la línea…'), 'aria' => __('Línea del programa'),
                            ])
                            @error('newProgramLine') <span class="kc-err">{{ $message }}</span> @enderror
                        </div>
                        @if ($newProgramHasAreas)
                            <div class="kc-field">
                                <span class="kc-field-label">{{ __('Área') }}</span>
                                @include('ai::livewire.knowledge._dropdown', [
                                    'model' => 'newProgramArea', 'live' => false, 'field' => true,
                                    'options' => $areas->mapWithKeys(fn ($a) => [$a->id => $a->name_es])->all(),
                                    'placeholder' => __('— elige un área —'), 'aria' => __('Área'),
                                ])
                                @error('newProgramArea') <span class="kc-err">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        <label class="kc-field">
                            <span class="kc-field-label">{{ __('URL de la ficha (opcional)') }}</span>
                            <input type="url" wire:model="newProgramUrl" class="kc-input" placeholder="https://" maxlength="500">
                            @error('newProgramUrl') <span class="kc-err">{{ $message }}</span> @enderror
                        </label>

                        @if ($inactiveMatchId !== null)
                            <div class="kc-modal-note">
                                {{ __('Ese programa ya existe pero está inactivo. Puedes activarlo en lugar de crear otro.') }}
                                <div style="margin-top:8px"><button type="button" wire:click="activateMatchedProgram" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="check" /> {{ __('Activar el existente') }}</button></div>
                            </div>
                        @endif

                        <div class="kc-modal-foot">
                            <button type="button" wire:click="closeAddProgram" class="kc-btn kc-btn-ghost kc-btn-sm">{{ __('Cancelar') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="createProgram" class="kc-btn kc-btn-primary kc-btn-sm">
                                <span wire:loading.remove wire:target="createProgram" class="kc-inl"><x-ui.icon name="plus" /> {{ __('Crear programa') }}</span>
                                <span wire:loading.inline-flex wire:target="createProgram" class="kc-gap"><span class="mca-spin"></span> {{ __('Creando…') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Modal «Importar programas» (alta masiva: revisar y después crear) --}}
        @if ($showImportPrograms)
            <div class="kc-modal-wrap" wire:click="closeImportPrograms">
                <div class="kc-modal kc-modal-wide" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-import-title">
                    <h2 id="kc-import-title">{{ __('Importar programas al catálogo') }}</h2>
                    <p>{!! __('Una fila por programa: <code>nombre | código | línea | área | URL</code>. Área solo en Microcredenciales (obligatoria ahí); URL opcional. Se admite pegar desde Excel (tabuladores) o separar con <code>|</code> o <code>;</code>.') !!}</p>
                    <p class="kc-auto-help">{{ __('Líneas válidas:') }} @foreach ($programLines as $slug => $label)<code>{{ $slug }}</code>@if (! $loop->last), @endif @endforeach</p>

                    <label class="kc-field">
                        <span class="kc-field-label">{{ __('Programas') }}</span>
                        <textarea wire:model="importText" class="kc-input kc-import-text" rows="7" spellcheck="false"
                                  placeholder="Micro MBA | MMBA-001 | micro_mba&#10;{{ __('Diploma Avanzado en Dirección Estratégica') }} | DA-020 | diplomas_avanzados"></textarea>
                        @error('importText') <span class="kc-err">{{ $message }}</span> @enderror
                    </label>

                    @if ($importPreview !== [])
                        @php $s = $importPreview['summary'] ?? []; @endphp
                        <div class="kc-import-summary">
                            @if ($importResult !== [])
                                <span class="kc-badge t-green">{{ __(':n creados', ['n' => $importResult['created']]) }}</span>
                                <span class="kc-badge t-amber">{{ __(':n ya existían', ['n' => $importResult['duplicate']]) }}</span>
                                <span class="kc-badge t-red">{{ __(':n omitidos por errores', ['n' => $importResult['error']]) }}</span>
                            @else
                                <span class="kc-badge t-green">{{ __(':n se crearán', ['n' => $s['create'] ?? 0]) }}</span>
                                <span class="kc-badge t-amber">{{ __(':n duplicados', ['n' => $s['duplicate'] ?? 0]) }}</span>
                                <span class="kc-badge t-red">{{ __(':n con errores', ['n' => $s['error'] ?? 0]) }}</span>
                            @endif
                            @if ($importPreview['truncated'] ?? false)
                                <span class="kc-badge t-gray">{{ __('Solo se procesan las primeras :n filas.', ['n' => \Modules\Catalog\Services\ProgramProvisioningService::MAX_BULK_ROWS]) }}</span>
                            @endif
                        </div>
                        <div class="kc-import-table">
                            <table>
                                <thead><tr><th>#</th><th>{{ __('Código') }}</th><th>{{ __('Nombre') }}</th><th>{{ __('Línea') }}</th><th>{{ __('Resultado') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($importPreview['rows'] ?? [] as $r)
                                        <tr wire:key="imp-{{ $r['line'] }}">
                                            <td class="t-mut">{{ $r['line'] }}</td>
                                            <td><span class="kc-code">{{ $r['code'] !== '' ? $r['code'] : '—' }}</span></td>
                                            <td>{{ $r['name'] !== '' ? $r['name'] : '—' }}</td>
                                            <td>{{ $r['program_line'] !== '' ? __($r['program_line']) : '—' }}</td>
                                            <td>
                                                @switch($r['status'])
                                                    @case('created') <span class="kc-badge t-green">{{ __('Creado') }}</span> @break
                                                    @case('create') <span class="kc-badge t-green">{{ __('Se creará') }}</span> @break
                                                    @case('duplicate') <span class="kc-badge t-amber">{{ __('Ya existe') }}</span> @break
                                                    @default <span class="kc-badge t-red">{{ __('Error') }}</span>
                                                @endswitch
                                                @if ($r['message'] !== '') <div class="kc-import-msg">{{ $r['message'] }}</div> @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="kc-modal-foot">
                        <button type="button" wire:click="closeImportPrograms" class="kc-btn kc-btn-ghost kc-btn-sm">{{ $importResult !== [] ? __('Cerrar') : __('Cancelar') }}</button>
                        @if ($importResult === [])
                            <button type="button" wire:click="previewImport" wire:loading.attr="disabled" wire:target="previewImport" class="kc-btn kc-btn-ghost kc-btn-sm"><x-ui.icon name="search" /> {{ __('Revisar') }}</button>
                            @if (($importPreview['summary']['create'] ?? 0) > 0)
                                <button type="button" wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" class="kc-btn kc-btn-primary kc-btn-sm">
                                    <span wire:loading.remove wire:target="confirmImport" class="kc-inl"><x-ui.icon name="check" /> {{ __('Crear :n programa(s)', ['n' => $importPreview['summary']['create']]) }}</span>
                                    <span wire:loading.inline-flex wire:target="confirmImport" class="kc-gap"><span class="mca-spin"></span> {{ __('Creando…') }}</span>
                                </button>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
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
