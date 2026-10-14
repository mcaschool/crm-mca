@php
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
    $kcNoFilters = $search === '' && $filterCategory === '' && $filterStatus === '' && $filterType === '';
    $kcActiveFilters = collect([$filterCategory, $filterType, $filterStatus])->filter(fn ($v) => $v !== '')->count();
@endphp

<div class="mca-page">
    <x-ui.page-header :title="__('Centro de Conocimiento')" :subtitle="__('Biblioteca central de fuentes, compartida entre todos los agentes.')">
        <x-slot:actions>
            <x-ui.button wire:click="syncLibrary" wire:loading.attr="disabled" wire:target="syncLibrary">
                <span wire:loading.remove wire:target="syncLibrary" class="ui-btn-label"><x-ui.icon name="refresh" /> {{ __('Sincronizar biblioteca') }}</span>
                <span wire:loading.inline-flex wire:target="syncLibrary"><span class="ui-spin"></span>&nbsp;{{ __('Sincronizando…') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stack">
        @include('ai::livewire.knowledge._tabs', ['active' => 'library'])

        @if (session('status'))
            <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        {{-- 1) Indicadores (clic = filtrar el listado) --}}
        <div class="ui-kpi-grid">
            <button type="button" wire:click="clearFilters" @class(['ui-kpi', 'is-on' => $kcNoFilters]) aria-pressed="{{ $kcNoFilters ? 'true' : 'false' }}">
                <span class="ui-kpi-top">
                    <span class="ui-kpi-ic ui-tone--blue"><x-ui.icon name="book-open" /></span>
                    <span class="ui-kpi-label">{{ __('Total de fuentes') }}</span>
                </span>
                <span class="ui-kpi-value">{{ $summary['total'] }}</span>
            </button>
            <button type="button" wire:click="$set('filterStatus', 'active')" @class(['ui-kpi', 'is-on' => $filterStatus === 'active']) aria-pressed="{{ $filterStatus === 'active' ? 'true' : 'false' }}">
                <span class="ui-kpi-top">
                    <span class="ui-kpi-ic ui-tone--success"><x-ui.icon name="check" /></span>
                    <span class="ui-kpi-label">{{ __('Activas') }}</span>
                </span>
                <span class="ui-kpi-value">{{ $summary['active'] }}</span>
            </button>
            <button type="button" wire:click="$set('filterStatus', 'inactive')" @class(['ui-kpi', 'is-on' => $filterStatus === 'inactive']) aria-pressed="{{ $filterStatus === 'inactive' ? 'true' : 'false' }}">
                <span class="ui-kpi-top">
                    <span class="ui-kpi-ic ui-tone--neutral"><x-ui.icon name="power" /></span>
                    <span class="ui-kpi-label">{{ __('Inactivas') }}</span>
                </span>
                <span class="ui-kpi-value">{{ $summary['inactive'] }}</span>
            </button>
            <button type="button" wire:click="filterByCategory('sin_categoria')" @class(['ui-kpi', 'is-on' => $filterCategory === 'sin_categoria']) aria-pressed="{{ $filterCategory === 'sin_categoria' ? 'true' : 'false' }}">
                <span class="ui-kpi-top">
                    <span class="ui-kpi-ic ui-tone--warning"><x-ui.icon name="alert-triangle" /></span>
                    <span class="ui-kpi-label">{{ __('Sin línea') }}</span>
                </span>
                <span class="ui-kpi-value">{{ $kcNoCat }}</span>
            </button>
        </div>

        {{-- 2) Chips de línea --}}
        <div class="ui-chips" role="group" aria-label="{{ __('Filtrar por línea') }}">
            <span class="ui-label ui-chips-label">{{ __('Filtrar por línea:') }}</span>
            <button type="button" wire:click="filterByCategory('')" class="ui-chip" aria-pressed="{{ $filterCategory === '' ? 'true' : 'false' }}">{{ __('Todas') }} · {{ $summary['total'] }}</button>
            @foreach ($kcCats as $c)
                <button type="button" wire:key="chip-{{ $c['key'] }}" wire:click="filterByCategory('{{ $c['key'] }}')" class="ui-chip" aria-pressed="{{ $filterCategory === $c['key'] ? 'true' : 'false' }}">{{ $c['label'] }} · {{ $c['count'] }}</button>
            @endforeach
        </div>

        {{-- 3) Espacios de subida: Programa Académico | Base de Conocimiento --}}
        <div class="ui-grid ui-grid--2">
            {{-- Programa Académico: línea (sin la institucional) + programa del catálogo --}}
            <section class="ui-card ui-entity" aria-labelledby="kc-space-program">
                <div class="ui-entity-h">
                    <span class="ui-icon-tile ui-tone--blue"><x-ui.icon name="book-open" /></span>
                    <div class="ui-person-text">
                        <h2 class="ui-entity-title" id="kc-space-program">{{ __('Programa Académico') }}</h2>
                        <span class="ui-cell-sub">{{ __('Contenido de un programa concreto del catálogo.') }}</span>
                    </div>
                </div>

                <div class="ui-entity-b ui-stack">
                    <x-ui.field :label="__('Línea')" for="kc-program-line" :error="$errors->first('programLine')">
                        {{-- En vivo: la línea filtra el desplegable de programas al momento --}}
                        @include('ai::livewire.knowledge._dropdown', [
                            'model' => 'programLine', 'live' => true, 'field' => true, 'id' => 'kc-program-line',
                            'options' => collect($programLines)->map(fn ($l) => __($l))->all(),
                            'placeholder' => __('Elige la línea…'), 'aria' => __('Línea del programa'),
                        ])
                    </x-ui.field>

                    {{-- Carga masiva por línea: el programa sale de cada archivo --}}
                    <button type="button" wire:click="$toggle('programAuto')" class="ui-switch ui-switch--flush"
                            role="switch" aria-checked="{{ $programAuto ? 'true' : 'false' }}">
                        <span class="ui-switch-track" aria-hidden="true"></span>
                        {{ __('Asignar programa automáticamente desde cada archivo') }}
                    </button>

                    {{-- Alta manual en el catálogo (solo quien puede gestionarlo; el servidor lo vuelve a comprobar) --}}
                    @php $kcProgramActions = auth()->user()?->can('create', \Modules\Catalog\Models\Program::class) ?? false; @endphp
                    @if ($programAuto)
                        <p class="ui-help">{!! __('Cada archivo indica su programa con <code>Programa: CÓDIGO</code> en el comentario de metadatos; si no lo trae, se busca por la URL de la ficha del programa. Los archivos que no se puedan asignar se rechazan con el motivo y el resto se sube.') !!}</p>
                        @if ($kcProgramActions)
                            <div class="ui-actions-row">
                                <x-ui.button size="sm" icon="plus" wire:click="openAddProgram">{{ __('Añadir programa') }}</x-ui.button>
                                <x-ui.button size="sm" icon="upload" wire:click="openImportPrograms">{{ __('Importar') }}</x-ui.button>
                            </div>
                        @endif
                    @else
                        <x-ui.field :label="__('Programa del catálogo')" for="kc-program-search" :error="$errors->first('programId')">
                            <x-ui.search id="kc-program-search" wire:model.live.debounce.300ms="programSearch" placeholder="{{ __('Buscar por nombre o código…') }}" aria-label="{{ __('Buscar programa') }}" />
                            @include('ai::livewire.knowledge._dropdown', [
                                'model' => 'programId', 'live' => false, 'field' => true,
                                'options' => $programs->mapWithKeys(fn ($p) => [$p->id => ($p->code ? $p->code.' · ' : '').$p->name_es])->all(),
                                'placeholder' => $programLine === '' ? __('Elige primero la línea…') : __('Elige un programa…'), 'aria' => __('Programa del catálogo'),
                            ])
                            @if ($kcProgramActions)
                                <div class="ui-actions-row">
                                    <x-ui.button size="sm" icon="plus" wire:click="openAddProgram">{{ __('Añadir programa') }}</x-ui.button>
                                    <x-ui.button size="sm" icon="upload" wire:click="openImportPrograms">{{ __('Importar') }}</x-ui.button>
                                </div>
                            @endif
                        </x-ui.field>
                    @endif

                    <div wire:loading wire:target="programDocs" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando archivos…') }}</div>
                    @error('programDocs') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                    @if (! empty($programDocs))
                        <div class="ui-chips">
                            @foreach ($programDocs as $d)
                                <x-ui.badge tone="neutral" icon="file-text" class="ui-badge--wrap ui-mono">{{ method_exists($d, 'getClientOriginalName') ? $d->getClientOriginalName() : '' }}</x-ui.badge>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="ui-card-f">
                    <label class="ui-btn ui-btn--secondary">
                        <x-ui.icon name="upload" /> {{ __('Elegir archivos') }}
                        <input type="file" wire:model="programDocs" accept=".md" multiple class="ui-sr">
                    </label>
                    <span class="sp"></span>
                    <x-ui.button variant="primary" wire:click="uploadProgramDocs" wire:loading.attr="disabled" wire:target="uploadProgramDocs,programDocs">
                        <span wire:loading.remove wire:target="uploadProgramDocs" class="ui-btn-label"><x-ui.icon name="check" /> {{ __('Subir') }}</span>
                        <span wire:loading.inline-flex wire:target="uploadProgramDocs"><span class="ui-spin"></span>&nbsp;{{ __('Procesando…') }}</span>
                    </x-ui.button>
                </div>
            </section>

            {{-- Base de Conocimiento: solo línea (incluida la institucional), sin programa --}}
            <section class="ui-card ui-entity" aria-labelledby="kc-space-kb">
                <div class="ui-entity-h">
                    <span class="ui-icon-tile ui-tone--gold"><x-ui.icon name="layers" /></span>
                    <div class="ui-person-text">
                        <h2 class="ui-entity-title" id="kc-space-kb">{{ __('Base de Conocimiento') }}</h2>
                        <span class="ui-cell-sub">{{ __('Admisiones, titulaciones y FAQs de toda una línea.') }}</span>
                    </div>
                </div>

                <div class="ui-entity-b ui-stack">
                    <x-ui.field :label="__('Línea')" for="kc-kb-line" :error="$errors->first('kbLine')">
                        @include('ai::livewire.knowledge._dropdown', [
                            'model' => 'kbLine', 'live' => false, 'field' => true, 'id' => 'kc-kb-line',
                            'options' => collect($lines)->map(fn ($l) => __($l))->all(),
                            'placeholder' => __('Elige la línea…'), 'aria' => __('Línea de la base de conocimiento'),
                        ])
                    </x-ui.field>

                    <div wire:loading wire:target="docs" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando archivos…') }}</div>
                    @error('docs') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                    @if (! empty($docs))
                        <div class="ui-chips">
                            @foreach ($docs as $d)
                                <x-ui.badge tone="neutral" icon="file-text" class="ui-badge--wrap ui-mono">{{ method_exists($d, 'getClientOriginalName') ? $d->getClientOriginalName() : '' }}</x-ui.badge>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="ui-card-f">
                    <label class="ui-btn ui-btn--secondary">
                        <x-ui.icon name="upload" /> {{ __('Elegir archivos') }}
                        <input type="file" wire:model="docs" accept=".md" multiple class="ui-sr">
                    </label>
                    <span class="sp"></span>
                    <x-ui.button variant="primary" wire:click="uploadDocs" wire:loading.attr="disabled" wire:target="uploadDocs,docs">
                        <span wire:loading.remove wire:target="uploadDocs" class="ui-btn-label"><x-ui.icon name="check" /> {{ __('Subir') }}</span>
                        <span wire:loading.inline-flex wire:target="uploadDocs"><span class="ui-spin"></span>&nbsp;{{ __('Procesando…') }}</span>
                    </x-ui.button>
                </div>
            </section>
        </div>
        <p class="ui-help">{{ __('Archivos .md: comentario con «Codigo», título «# » y al menos una sección «## ». Máx. 512 KB por archivo. Si el archivo declara «Categoria», debe coincidir con la línea elegida.') }}</p>

        @if ($uploadResults !== [])
            @php $kcCount = collect($uploadResults)->countBy('result'); @endphp
            <section class="ui-card ui-card--list" aria-labelledby="kc-upload-title">
                <div class="ui-card-h">
                    <x-ui.icon name="upload" class="ui-card-ic" />
                    <h2 id="kc-upload-title">{{ __('Resultado de la subida') }}</h2>
                    <span class="ui-card-sub">{{ __(':new nuevos · :upd actualizados · :rej rechazados', ['new' => $kcCount['Nuevo'] ?? 0, 'upd' => $kcCount['Actualizado'] ?? 0, 'rej' => $kcCount['Rechazado'] ?? 0]) }}</span>
                </div>
                <div class="mca-table-scroll">
                    <table class="ui-table ui-table--dense">
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
                                @php $tone = match ($r['result']) { 'Nuevo' => 'success', 'Actualizado' => 'blue', default => 'danger' }; @endphp
                                <tr>
                                    <td class="rt-primary rt-w-md"><span class="ui-mono">{{ $r['file'] }}</span></td>
                                    <td class="rt-w-md" data-label="{{ __('Programa asignado') }}">{{ $r['program'] ?? '—' }}</td>
                                    <td class="rt-aside"><x-ui.badge :tone="$tone" dot>{{ __($r['result']) }}</x-ui.badge></td>
                                    <td class="rt-w-lg t-2" data-label="{{ __('Motivo') }}">{{ $r['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- 4) Listado de fuentes: barra de herramientas + tabla --}}
        <div class="ui-card ui-card--list">
            <div class="ui-toolbar">
                <x-ui.search wire:model.live.debounce.350ms="search" placeholder="{{ __('Buscar por código o nombre…') }}" aria-label="{{ __('Buscar por código o nombre') }}" />
                <x-ui.filters :active="$kcActiveFilters">
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
                    @if (! $kcNoFilters)
                        <x-ui.button variant="ghost" icon="x" wire:click="clearFilters">{{ __('Limpiar') }}</x-ui.button>
                    @endif
                </x-ui.filters>
            </div>

            {{-- Prioridad y secciones: columnas si caben; si no, bajo el nombre (contenedor ≤ 960 px). --}}
            <div class="mca-table-scroll ui-cq">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="ui-cq-wide">{{ __('Código') }}</th>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Línea / tipo') }}</th>
                            <th class="ui-cq-wide t-end">{{ __('Prior.') }}</th>
                            <th class="ui-cq-wide">{{ __('Secc.') }}</th>
                            <th>{{ __('Agentes') }}</th>
                            <th>{{ __('Estado') }}</th>
                            <th class="t-end">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sources as $s)
                            @php $active = $s['status'] === 'active'; @endphp
                            <tr wire:key="ks-{{ $s['id'] }}">
                                <td class="ui-cq-wide" data-label="{{ __('Código') }}"><span class="ui-mono">{{ $s['code'] }}</span></td>
                                <td class="rt-primary rt-w-lg" title="{{ $s['last_synced_at'] ? __('Sincronizado :t', ['t' => $s['last_synced_at']->diffForHumans()]) : __('Sin sincronizar') }}">
                                    <span class="ui-cell-title">{{ $s['name'] }}</span>
                                    @if ($s['program'])
                                        <span @class(['t-sub', 'ui-strike' => $s['program']['gone']]) title="{{ $s['program']['name'] }}{{ $s['program']['gone'] ? ' — '.__('ya no está activo en el catálogo') : '' }}">
                                            <x-ui.icon name="book-open" class="ui-inline-ic" /> {{ $s['program']['name'] }}
                                        </span>
                                    @endif
                                    <span class="t-sub ui-cq-narrow"><span class="ui-mono">{{ $s['code'] }}</span> · {{ __('Prior. :p · :n secc.', ['p' => $s['priority'], 'n' => $s['sections']]) }}</span>
                                </td>
                                <td data-label="{{ __('Línea / tipo') }}">
                                    @php
                                        $catKnown = KnowledgeTaxonomy::isLine($s['category']);
                                        $catLabel = $s['category'] === null ? null : ($catKnown ? __((string) KnowledgeTaxonomy::lineLabel($s['category'])) : $s['category']);
                                        $typeLabel = KnowledgeTaxonomy::typeLabel($s['type']);
                                        $typeLabel = $typeLabel !== null ? __($typeLabel) : null;
                                    @endphp
                                    @if ($catLabel)
                                        <x-ui.badge :tone="$catKnown ? 'blue' : 'warning'" title="{{ $catKnown ? $catLabel : __(':v — fuera de la lista de líneas', ['v' => $catLabel]) }}">{{ $catLabel }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="warning">{{ __('Sin línea') }}</x-ui.badge>
                                    @endif
                                    <span @class(['t-sub', 'ui-text-warning' => $typeLabel === null])>{{ $typeLabel ?? __('Sin tipo') }}</span>
                                </td>
                                <td class="ui-cq-wide t-end t-2" data-label="{{ __('Prior.') }}">{{ $s['priority'] }}</td>
                                <td class="ui-cq-wide t-2" data-label="{{ __('Secc.') }}">{{ $s['sections'] }}</td>
                                <td data-label="{{ __('Agentes') }}">
                                    @if ($s['agents'] !== [])
                                        <span class="ui-avatars" title="{{ implode(', ', $s['agents']) }}">
                                            @foreach (array_slice($s['agents'], 0, 3) as $agentName)
                                                <x-ui.avatar :name="$agentName" class="ui-avatar--sm" />
                                            @endforeach
                                            @if (count($s['agents']) > 3)
                                                <span class="ui-avatar ui-avatar--sm ui-tone--neutral" aria-hidden="true">+{{ count($s['agents']) - 3 }}</span>
                                            @endif
                                            <span class="ui-sr">{{ implode(', ', $s['agents']) }}</span>
                                        </span>
                                    @else
                                        <span class="t-nil">— {{ __('Ninguno') }} —</span>
                                    @endif
                                </td>
                                <td class="rt-aside" data-label="{{ __('Estado') }}">
                                    @if ($active)
                                        <x-ui.badge tone="success" dot>{{ __('Activa') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="neutral" dot>{{ __('Inactiva') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="rt-actions">
                                    <div class="t-actions">
                                        <x-ui.button icon="eye" wire:click="view({{ $s['id'] }})" title="{{ __('Ver contenido') }}" aria-label="{{ __('Ver contenido') }}" />
                                        <x-ui.button icon="power" wire:click="toggleStatus({{ $s['id'] }})" title="{{ $active ? __('Desactivar') : __('Activar') }}" aria-label="{{ $active ? __('Desactivar') : __('Activar') }}" />
                                        <x-ui.button variant="danger" icon="trash" wire:click="confirmDelete({{ $s['id'] }})" title="{{ __('Borrar') }}" aria-label="{{ __('Borrar') }}" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="rt-empty">
                                    <div class="ui-empty">
                                        <div class="ui-empty-ic"><x-ui.icon name="book-open" /></div>
                                        {{ __('No hay fuentes que coincidan. Sube archivos .md o ajusta los filtros.') }}
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ui-pager"><span>{{ __('Mostrando :n de :t fuentes', ['n' => count($sources), 't' => $summary['total']]) }}</span></div>
        </div>
    </div>

    {{-- Contenido de la fuente (antes panel lateral; ahora ventana del kit) --}}
    @if ($viewingId !== null)
        <div class="ui-modal-bg" wire:click="closeDrawer">
            <div class="ui-modal ui-modal--lg" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-view-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="kc-view-title"><x-ui.icon name="file-text" /> {{ $viewingName }}</h2>
                    <x-ui.button variant="ghost" icon="x" wire:click="closeDrawer" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    {{-- Enlace oficial: lo usa el asesor en las respuestas de catálogo de esta línea --}}
                    <form wire:submit="saveUrl" class="ui-field" data-testid="official-link">
                        <label class="ui-label" for="kc-official-url">{{ __('Enlace oficial') }}</label>
                        <div class="ui-input-row">
                            <input id="kc-official-url" type="url" wire:model="viewingUrl" placeholder="https://" maxlength="500" @disabled(! $canEditUrl)
                                @class(['ui-input', 'is-invalid' => $errors->has('viewingUrl')]) @error('viewingUrl') aria-describedby="kc-official-url-error" @enderror>
                            @if ($canEditUrl)
                                <x-ui.button type="submit" variant="primary">{{ __('Guardar') }}</x-ui.button>
                            @endif
                        </div>
                        <p class="ui-help">{{ __('Página pública que el asesor comparte al listar los programas de esta línea. Solo https.') }}</p>
                        @error('viewingUrl') <p class="ui-error" id="kc-official-url-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                    </form>
                    <div @class(['ui-doc' => $viewingSections !== []])>
                        @if ($viewingSections !== [])
                            <nav aria-label="{{ __('Secciones') }}">
                                <p class="ui-card-kicker ui-card-kicker--flush">{{ __('Secciones') }}</p>
                                <ul class="ui-doc-nav">
                                    @foreach ($viewingSections as $sec)
                                        <li>{{ $sec }}</li>
                                    @endforeach
                                </ul>
                            </nav>
                        @endif
                        <div class="ui-prose">{!! $viewingHtml !!}</div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal «Añadir programa» (alta individual en el catálogo) --}}
    @if ($showAddProgram)
        <div class="ui-modal-bg" wire:click="closeAddProgram">
            <div class="ui-modal ui-modal--sm" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-add-program-title">
                <div class="ui-modal-h">
                    <div>
                        <h2 class="ui-modal-title" id="kc-add-program-title"><x-ui.icon name="plus" /> {{ __('Añadir programa al catálogo') }}</h2>
                        <p class="ui-modal-sub">{{ __('Se crea en el catálogo institucional, activo, y queda elegido para subir su ficha.') }}</p>
                    </div>
                    <x-ui.button variant="ghost" icon="x" wire:click="closeAddProgram" :aria-label="__('Cerrar')" />
                </div>
                <form wire:submit="createProgram" class="ui-modal-form">
                    <div class="ui-modal-b">
                        <x-ui.field :label="__('Nombre del programa')" for="kc-np-name" :error="$errors->first('newProgramName')">
                            <input id="kc-np-name" type="text" wire:model="newProgramName" placeholder="{{ __('Ej.: Micro MBA') }}" maxlength="200" autofocus
                                @class(['ui-input', 'is-invalid' => $errors->has('newProgramName')]) @error('newProgramName') aria-describedby="kc-np-name-error" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('Código')" for="kc-np-code" :error="$errors->first('newProgramCode')">
                            <input id="kc-np-code" type="text" wire:model="newProgramCode" placeholder="{{ __('Ej.: MMBA-001') }}" maxlength="40"
                                @class(['ui-input', 'is-invalid' => $errors->has('newProgramCode')]) @error('newProgramCode') aria-describedby="kc-np-code-error" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('Línea')" for="kc-np-line" :error="$errors->first('newProgramLine')">
                            @include('ai::livewire.knowledge._dropdown', [
                                'model' => 'newProgramLine', 'live' => true, 'field' => true, 'id' => 'kc-np-line',
                                'options' => collect($programLines)->map(fn ($l) => __($l))->all(),
                                'placeholder' => __('Elige la línea…'), 'aria' => __('Línea del programa'),
                            ])
                        </x-ui.field>
                        @if ($newProgramHasAreas)
                            <x-ui.field :label="__('Área')" for="kc-np-area" :error="$errors->first('newProgramArea')">
                                @include('ai::livewire.knowledge._dropdown', [
                                    'model' => 'newProgramArea', 'live' => false, 'field' => true, 'id' => 'kc-np-area',
                                    'options' => $areas->mapWithKeys(fn ($a) => [$a->id => $a->name_es])->all(),
                                    'placeholder' => __('— elige un área —'), 'aria' => __('Área'),
                                ])
                            </x-ui.field>
                        @endif
                        <x-ui.field :label="__('URL de la ficha (opcional)')" for="kc-np-url" :error="$errors->first('newProgramUrl')">
                            <input id="kc-np-url" type="url" wire:model="newProgramUrl" placeholder="https://" maxlength="500"
                                @class(['ui-input', 'is-invalid' => $errors->has('newProgramUrl')]) @error('newProgramUrl') aria-describedby="kc-np-url-error" @enderror>
                        </x-ui.field>

                        @if ($inactiveMatchId !== null)
                            <div class="ui-callout ui-tone--warning ui-callout--stack">
                                <span>{{ __('Ese programa ya existe pero está inactivo. Puedes activarlo en lugar de crear otro.') }}</span>
                                <div><x-ui.button size="sm" icon="check" wire:click="activateMatchedProgram">{{ __('Activar el existente') }}</x-ui.button></div>
                            </div>
                        @endif
                    </div>

                    <div class="ui-modal-f">
                        <x-ui.button variant="ghost" wire:click="closeAddProgram">{{ __('Cancelar') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createProgram">
                            <span wire:loading.remove wire:target="createProgram" class="ui-btn-label"><x-ui.icon name="plus" /> {{ __('Crear programa') }}</span>
                            <span wire:loading.inline-flex wire:target="createProgram"><span class="ui-spin"></span>&nbsp;{{ __('Creando…') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal «Importar programas» (alta masiva: revisar y después crear) --}}
    @if ($showImportPrograms)
        <div class="ui-modal-bg" wire:click="closeImportPrograms">
            <div class="ui-modal ui-modal--md" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-import-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="kc-import-title"><x-ui.icon name="upload" /> {{ __('Importar programas al catálogo') }}</h2>
                    <x-ui.button variant="ghost" icon="x" wire:click="closeImportPrograms" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    <p class="ui-help">{!! __('Una fila por programa: <code>nombre | código | línea | área | URL</code>. Área solo en Microcredenciales (obligatoria ahí); URL opcional. Se admite pegar desde Excel (tabuladores) o separar con <code>|</code> o <code>;</code>.') !!}</p>
                    <p class="ui-help">{{ __('Líneas válidas:') }} @foreach ($programLines as $slug => $label)<code>{{ $slug }}</code>@if (! $loop->last), @endif @endforeach</p>

                    <x-ui.field :label="__('Programas')" for="kc-import-text" :error="$errors->first('importText')">
                        <textarea id="kc-import-text" wire:model="importText" rows="7" spellcheck="false"
                                  @class(['ui-input', 'ui-textarea', 'ui-mono', 'is-invalid' => $errors->has('importText')]) @error('importText') aria-describedby="kc-import-text-error" @enderror
                                  placeholder="Micro MBA | MMBA-001 | micro_mba&#10;{{ __('Diploma Avanzado en Dirección Estratégica') }} | DA-020 | diplomas_avanzados"></textarea>
                    </x-ui.field>

                    @if ($importPreview !== [])
                        @php $s = $importPreview['summary'] ?? []; @endphp
                        <div class="ui-chips" role="status">
                            @if ($importResult !== [])
                                <x-ui.badge tone="success">{{ __(':n creados', ['n' => $importResult['created']]) }}</x-ui.badge>
                                <x-ui.badge tone="warning">{{ __(':n ya existían', ['n' => $importResult['duplicate']]) }}</x-ui.badge>
                                <x-ui.badge tone="danger">{{ __(':n omitidos por errores', ['n' => $importResult['error']]) }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="success">{{ __(':n se crearán', ['n' => $s['create'] ?? 0]) }}</x-ui.badge>
                                <x-ui.badge tone="warning">{{ __(':n duplicados', ['n' => $s['duplicate'] ?? 0]) }}</x-ui.badge>
                                <x-ui.badge tone="danger">{{ __(':n con errores', ['n' => $s['error'] ?? 0]) }}</x-ui.badge>
                            @endif
                            @if ($importPreview['truncated'] ?? false)
                                <x-ui.badge tone="neutral" class="ui-badge--wrap">{{ __('Solo se procesan las primeras :n filas.', ['n' => \Modules\Catalog\Services\ProgramProvisioningService::MAX_BULK_ROWS]) }}</x-ui.badge>
                            @endif
                        </div>
                        <div class="ui-scroll-box">
                            <table class="ui-table ui-table--dense">
                                <thead><tr><th>#</th><th>{{ __('Código') }}</th><th>{{ __('Nombre') }}</th><th>{{ __('Línea') }}</th><th>{{ __('Resultado') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($importPreview['rows'] ?? [] as $r)
                                        <tr wire:key="imp-{{ $r['line'] }}">
                                            <td class="t-3" data-label="#">{{ $r['line'] }}</td>
                                            <td data-label="{{ __('Código') }}"><span class="ui-mono">{{ $r['code'] !== '' ? $r['code'] : '—' }}</span></td>
                                            <td class="rt-primary">{{ $r['name'] !== '' ? $r['name'] : '—' }}</td>
                                            <td data-label="{{ __('Línea') }}">{{ $r['program_line'] !== '' ? __($r['program_line']) : '—' }}</td>
                                            <td data-label="{{ __('Resultado') }}">
                                                @switch($r['status'])
                                                    @case('created') <x-ui.badge tone="success">{{ __('Creado') }}</x-ui.badge> @break
                                                    @case('create') <x-ui.badge tone="success">{{ __('Se creará') }}</x-ui.badge> @break
                                                    @case('duplicate') <x-ui.badge tone="warning">{{ __('Ya existe') }}</x-ui.badge> @break
                                                    @default <x-ui.badge tone="danger">{{ __('Error') }}</x-ui.badge>
                                                @endswitch
                                                @if ($r['message'] !== '') <span class="t-sub">{{ $r['message'] }}</span> @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="ui-modal-f">
                    <x-ui.button variant="ghost" wire:click="closeImportPrograms">{{ $importResult !== [] ? __('Cerrar') : __('Cancelar') }}</x-ui.button>
                    @if ($importResult === [])
                        <x-ui.button icon="search" wire:click="previewImport" wire:loading.attr="disabled" wire:target="previewImport">{{ __('Revisar') }}</x-ui.button>
                        @if (($importPreview['summary']['create'] ?? 0) > 0)
                            <x-ui.button variant="primary" wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport">
                                <span wire:loading.remove wire:target="confirmImport" class="ui-btn-label"><x-ui.icon name="check" /> {{ __('Crear :n programa(s)', ['n' => $importPreview['summary']['create']]) }}</span>
                                <span wire:loading.inline-flex wire:target="confirmImport"><span class="ui-spin"></span>&nbsp;{{ __('Creando…') }}</span>
                            </x-ui.button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Modal de borrado --}}
    @if ($deletingId !== null)
        <div class="ui-modal-bg" wire:click="cancelDelete">
            <div class="ui-modal ui-modal--sm" wire:click.stop role="dialog" aria-modal="true" aria-labelledby="kc-delete-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="kc-delete-title"><x-ui.icon name="alert-triangle" class="ui-text-danger" /> {{ __('Borrar fuente de conocimiento') }}</h2>
                    <x-ui.button variant="ghost" icon="x" wire:click="cancelDelete" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    <p class="ui-note-body">{{ __('Vas a eliminar «:name». Se borrará la fila, sus asignaciones a agentes y su archivo .md. Esta acción no se puede deshacer.', ['name' => $deletingName]) }}</p>
                    @if ($deletingAgents !== [])
                        <div class="ui-callout ui-tone--warning"><x-ui.icon name="alert-triangle" /><span><b>{{ __('La usan :n agente(s):', ['n' => count($deletingAgents)]) }}</b> {{ implode(', ', $deletingAgents) }}</span></div>
                    @else
                        <p class="ui-help">{{ __('Ningún agente la tiene asignada.') }}</p>
                    @endif
                </div>
                <div class="ui-modal-f">
                    <x-ui.button variant="ghost" wire:click="cancelDelete">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button variant="danger" wire:click="delete" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="delete" class="ui-btn-label"><x-ui.icon name="trash" /> {{ __('Borrar definitivamente') }}</span>
                        <span wire:loading.inline-flex wire:target="delete"><span class="ui-spin"></span>&nbsp;{{ __('Borrando…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
