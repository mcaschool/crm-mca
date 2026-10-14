{{-- Pipeline comercial (x-ui.kit · .ui-board): una columna por etapa con su recuento.
     ≥1088 px de contenido: todas las etapas a la vista; más estrecho: desplazamiento horizontal
     por columnas (scroll-snap); <600 px: pestañas de etapa y una columna a la vez.
     Las clases pl-col / pl-card / pl-flag son ganchos que cuentan los tests (sin estilos propios). --}}
<div class="mca-page">
    @php
        $now = now();
        $openStages = ['new', 'contacted', 'qualified'];
        $stageTone = ['new' => 'blue', 'contacted' => 'teal', 'qualified' => 'warning', 'enrolled' => 'success'];
        $interestTone = ['high' => 'success', 'medium' => 'warning'];
        $activeFilters = collect([$scope === 'team' ? $advisor : '', $program, $product, $interest, $period])->filter(fn ($v) => (string) $v !== '')->count();
    @endphp

    <x-ui.page-header :title="__('Pipeline Comercial')" :count="$total" />

    <div class="ui-stack">
        <div class="ui-card ui-card--list ui-board-bar">
            <div class="ui-toolbar ui-toolbar--flush">
                <div class="ui-seg" role="group" aria-label="{{ __('Alcance') }}">
                    <button type="button" wire:click="$set('scope', 'mine')" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}">{{ __('Mis leads') }}</button>
                    <button type="button" wire:click="$set('scope', 'team')" aria-pressed="{{ $scope === 'team' ? 'true' : 'false' }}">{{ __('Equipo') }}</button>
                </div>

                <x-ui.search wire:model.live.debounce.400ms="search" :placeholder="__('Buscar por nombre o correo…')" :aria-label="__('Buscar por nombre o correo')" maxlength="100" />

                <x-ui.filters :active="$activeFilters">
                    @if ($scope === 'team')
                        <x-ui.select filter :active="$advisor !== ''" wire:model.live="advisor" :aria-label="__('Asesor responsable')">
                            <option value="">{{ __('Asesor responsable · todos') }}</option>
                            @foreach ($advisors as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.select filter :active="$program !== ''" wire:model.live="program" :aria-label="__('Programa')">
                        <option value="">{{ __('Programa · todos') }}</option>
                        @foreach ($programs as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select filter :active="$product !== ''" wire:model.live="product" :aria-label="__('Tipo de producto')">
                        <option value="">{{ __('Producto · todos') }}</option>
                        @foreach ($products as $code)
                            <option value="{{ $code }}">{{ (new \Modules\Crm\Models\Lead(['product_type' => $code]))->productLabel() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select filter :active="$interest !== ''" wire:model.live="interest" :aria-label="__('Nivel de interés')">
                        <option value="">{{ __('Interés · todos') }}</option>
                        @foreach ($interests as $i)
                            <option value="{{ $i->value }}">{{ __($i->label()) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select filter :active="$period !== ''" wire:model.live="period" :aria-label="__('Período de captación')">
                        <option value="">{{ __('Período · siempre') }}</option>
                        <option value="7">{{ __('Últimos 7 días') }}</option>
                        <option value="30">{{ __('Últimos 30 días') }}</option>
                        <option value="90">{{ __('Últimos 90 días') }}</option>
                    </x-ui.select>
                </x-ui.filters>

                @if ($filtered)
                    <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('Quitar filtros') }}</x-ui.button>
                @endif
            </div>
        </div>

        @if ($notice !== '')
            <div class="ui-alert ui-alert--flush ui-tone--{{ $noticeError ? 'danger' : 'success' }}" role="status" aria-live="polite">
                <x-ui.icon name="{{ $noticeError ? 'alert-triangle' : 'check' }}" /> {{ $notice }}
            </div>
        @endif

        <div class="ui-board"
             x-data="{
                tab: 'new', drag: null, over: null,
                start(e, id, from) { this.drag = { id, from }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(id)); },
                drop(to) { const d = this.drag; this.drag = null; this.over = null; if (! d || d.from === to) { return; } this.$wire.move(d.id, d.from, to); },
             }"
             wire:loading.class="is-busy" wire:target="move">
            <div class="ui-board-tabs ui-chips" role="tablist" aria-label="{{ __('Etapas') }}">
                @foreach ($columns as $value => $col)
                    <button type="button" class="ui-chip" role="tab" @click="tab = '{{ $value }}'" :aria-selected="tab === '{{ $value }}' ? 'true' : 'false'">
                        {{ __($col['status']->label()) }} <span class="ui-board-tab-n">{{ $col['total'] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="ui-board-cols">
                @foreach ($columns as $value => $col)
                    <section class="pl-col" wire:key="col-{{ $value }}" aria-label="{{ __($col['status']->label()) }}"
                             :class="{ 'is-hidden-sm': tab !== '{{ $value }}', 'is-over': over === '{{ $value }}' }"
                             @dragover.prevent="if (drag && drag.from !== '{{ $value }}') { over = '{{ $value }}' }"
                             @dragleave.self="if (over === '{{ $value }}') { over = null }"
                             @drop.prevent="drop('{{ $value }}')">
                        <div class="ui-board-col-h">
                            <x-ui.badge :tone="$stageTone[$value] ?? 'neutral'" dot>{{ __($col['status']->label()) }}</x-ui.badge>
                            <span class="ui-badge ui-badge--count ui-tone--neutral" title="{{ __('Total con los filtros actuales') }}">{{ $col['total'] }}</span>
                        </div>
                        <div class="ui-board-list">
                            @forelse ($col['cards'] as $lead)
                                @php
                                    $cal = $calendar[$lead->id] ?? ['pending' => 0, 'overdue' => 0, 'next' => null, 'last_at' => null];
                                    $next = $cal['next'];
                                    $last = $cal['last_at'] !== null && $cal['last_at']->gt($lead->updated_at) ? $cal['last_at'] : $lead->updated_at;
                                    $name = $lead->contact?->fullName() ?: __('Sin nombre');
                                    $canMove = $movable[$lead->id] ?? false;
                                    $owner = $lead->assignedUser?->name ?? ($lead->assigned_to_department ?: __('Sin asignar'));
                                @endphp
                                <article class="pl-card" wire:key="lead-{{ $lead->id }}"
                                         @if ($canMove) draggable="true" @dragstart="start($event, {{ $lead->id }}, '{{ $value }}')" @dragend="drag = null; over = null" :class="drag && drag.id === {{ $lead->id }} ? 'is-dragging' : ''" @endif>
                                    <div class="ui-board-card-h">
                                        <a class="ui-person-name" href="{{ route('crm.leads.show', $lead) }}">{{ $name }}</a>
                                        <x-ui.badge :tone="$interestTone[$lead->interest_level->value] ?? 'neutral'" title="{{ __('Nivel de interés') }}">{{ __($lead->interest_level->label()) }}</x-ui.badge>
                                    </div>
                                    <p class="ui-board-line"><b>{{ $lead->program?->name ?? __('Sin programa') }}</b> · {{ $lead->productLabel() }}</p>
                                    <p class="ui-board-line">{{ __('Asesor') }}: <b>{{ $owner }}</b> · {{ __('Origen') }}: {{ ($lead->capturedByLabel() === 'Recomendador InCompany' ? __('Recomendador InCompany') : $lead->capturedByLabel()) }}</p>

                                    @if (in_array($value, $openStages, true))
                                        @if ($cal['overdue'] > 0)
                                            <div class="pl-flag overdue"><x-ui.badge tone="danger" dot>{{ trans_choice('Seguimiento vencido|:n seguimientos vencidos', $cal['overdue'], ['n' => $cal['overdue']]) }}</x-ui.badge></div>
                                        @elseif ($next !== null && $next->starts_at->lte($now->copy()->addHours(48)))
                                            <div class="pl-flag soon"><x-ui.badge tone="blue" dot>{{ __('Actividad próxima') }}</x-ui.badge></div>
                                        @elseif ($next === null)
                                            <div class="pl-flag none"><x-ui.badge tone="neutral" dot>{{ __('Sin próxima acción programada') }}</x-ui.badge></div>
                                        @endif
                                    @endif

                                    @if ($next !== null)
                                        <div class="ui-board-next">
                                            <x-ui.icon name="calendar" />
                                            <a href="{{ route('crm.agenda.show', $next) }}">{{ $next->type->label() }} · {{ $next->localStart($tz)->format('d/m H:i') }}</a>
                                            @include('crm::livewire.agenda.partials.join', ['a' => $next, 'compact' => false])
                                        </div>
                                    @endif
                                    <p class="ui-cell-sub">
                                        {{ __('Última actividad') }}: {{ $last->copy()->setTimezone($tz)->format('d/m/Y') }}
                                        @if ($cal['pending'] > 0) · {{ __('Pendientes: :n', ['n' => $cal['pending']]) }}@endif
                                    </p>

                                    <div class="ui-board-acts">
                                        <div class="ui-board-links">
                                            <a class="ui-link" href="{{ route('crm.leads.show', $lead) }}">{{ __('Ver lead') }}</a>
                                            <a class="ui-link" href="{{ route('crm.agenda.create', ['lead' => $lead->id]) }}">{{ __('Programar actividad') }}</a>
                                        </div>
                                        @if ($lead->status->isTerminal())
                                            <x-ui.badge tone="neutral" icon="check">{{ __('Etapa final') }}</x-ui.badge>
                                        @elseif ($canMove)
                                            <select class="ui-select" aria-label="{{ __('Cambiar etapa de :name', ['name' => $name]) }}"
                                                    @change="if ($event.target.value) { $wire.move({{ $lead->id }}, '{{ $value }}', $event.target.value); $event.target.value = '' }">
                                                <option value="">{{ __('Cambiar etapa…') }}</option>
                                                @foreach ($statuses as $s)
                                                    @if ($s->value !== $value)
                                                        <option value="{{ $s->value }}">{{ __($s->label()) }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <p class="ui-board-empty">{{ __('Sin leads en esta etapa.') }}</p>
                            @endforelse
                        </div>
                        @if ($col['cards']->count() < $col['total'])
                            <x-ui.button size="sm" class="ui-btn--block" wire:click="loadMore('{{ $value }}')" wire:loading.attr="disabled">
                                {{ __('Cargar más (:n restantes)', ['n' => $col['total'] - $col['cards']->count()]) }}
                            </x-ui.button>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</div>
