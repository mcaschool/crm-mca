<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')
    <style>
        .crm-page .pl-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
        .crm-page .pl-seg button{border:0;background:#fff;padding:8px 12px;font:inherit;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}
        .crm-page .pl-seg button+button{border-left:1px solid var(--line)}
        .crm-page .pl-seg button[aria-pressed="true"]{background:var(--blue);color:#fff}
        .crm-page .pl-search{flex:1 1 240px}
        .crm-page .pl-filters{display:flex;flex-wrap:wrap;gap:8px;padding:12px 18px;align-items:center}
        .crm-page .pl-filters select{max-width:220px}
        .crm-page .pl-clear{border:0;background:none;color:var(--blue);font:inherit;font-size:13px;font-weight:600;cursor:pointer}
        .crm-page .pl-board{margin-top:16px}
        .crm-page .pl-tabs{display:none}
        .crm-page .pl-cols{display:grid;grid-template-columns:repeat(5,minmax(250px,1fr));gap:12px;overflow-x:auto;padding-bottom:8px;align-items:start}
        .crm-page .pl-col{background:#F4F7FB;border:1px solid var(--line);border-radius:16px;padding:10px;min-width:0;transition:box-shadow .15s,border-color .15s}
        .crm-page .pl-col.pl-over{border-color:var(--blue-3);box-shadow:0 0 0 3px rgba(59,130,214,.18)}
        .crm-page .pl-col-h{display:flex;align-items:center;gap:8px;padding:2px 4px 10px}
        .crm-page .pl-col-h .count{margin-left:auto}
        .crm-page .pl-list{display:flex;flex-direction:column;gap:8px;min-height:60px}
        .crm-page .pl-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:11px 12px;box-shadow:0 1px 2px rgba(19,37,61,.04);font-size:12.5px;color:var(--muted)}
        .crm-page .pl-card[draggable="true"]{cursor:grab}
        .crm-page .pl-card.pl-dragging{opacity:.45}
        .crm-page .pl-top{display:flex;align-items:flex-start;gap:8px}
        .crm-page .pl-name{font-size:14px;font-weight:700;color:var(--ink);text-decoration:none;line-height:1.3;overflow-wrap:anywhere}
        .crm-page .pl-name:hover{color:var(--blue)}
        .crm-page .pl-int{margin-left:auto;font-size:11px;font-weight:600;border-radius:999px;padding:2px 8px;white-space:nowrap}
        .crm-page .pl-int.high{background:#E3F5EA;color:#1E8E5A}
        .crm-page .pl-int.medium{background:#FBF1DC;color:#B7791F}
        .crm-page .pl-int.low{background:#EEF1F5;color:#6B7C93}
        .crm-page .pl-line{margin-top:5px;line-height:1.4;overflow-wrap:anywhere}
        .crm-page .pl-line b{color:var(--ink);font-weight:600}
        .crm-page .pl-flag{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;margin-top:7px}
        .crm-page .pl-flag::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
        .crm-page .pl-flag.overdue{color:#B23B3B}
        .crm-page .pl-flag.soon{color:#1E5AA8}
        .crm-page .pl-flag.none{color:#8A99B2}
        .crm-page .pl-next{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px;padding:6px 8px;border-radius:9px;background:#F3F8FE;color:var(--ink)}
        .crm-page .pl-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;padding-top:9px;border-top:1px dashed var(--line);align-items:center}
        .crm-page .pl-acts a{font-size:12px;font-weight:600;color:var(--blue);text-decoration:none}
        .crm-page .pl-stage{margin-left:auto;font:inherit;font-size:12px;border:1px solid var(--line);border-radius:8px;padding:4px 6px;background:#fff;color:var(--ink);max-width:150px}
        .crm-page .pl-final{margin-left:auto;font-size:11.5px;color:var(--muted)}
        .crm-page .pl-more{width:100%;margin-top:8px;border:1px dashed #C9D6E8;background:#fff;border-radius:10px;padding:8px;font:inherit;font-size:12.5px;font-weight:600;color:var(--blue);cursor:pointer}
        .crm-page .pl-empty{font-size:12.5px;color:#8A99B2;text-align:center;padding:16px 6px}
        .crm-page .pl-busy .pl-cols{opacity:.7;pointer-events:none}
        @media (max-width:760px){
            .crm-page .pl-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;margin-bottom:4px}
            .crm-page .pl-tabs button{flex:0 0 auto;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 12px;font:inherit;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;display:inline-flex;gap:6px;align-items:center}
            .crm-page .pl-tabs button[aria-selected="true"]{background:var(--blue);border-color:var(--blue);color:#fff}
            .crm-page .pl-cols{display:block;overflow:visible}
            .crm-page .pl-col.pl-m-hide{display:none}
            .crm-page .pl-filters select{max-width:100%;min-width:0;flex:1 1 45%;text-overflow:ellipsis}
            .crm-page .toolbar .search{min-width:0;width:100%;margin-left:0}
        }
    </style>

    @php
        $now = now();
        $openStages = ['new', 'contacted', 'qualified'];
    @endphp

    <div class="panel">
        <div class="toolbar">
            <h2 style="text-transform:none"><x-ui.icon name="funnel" class="i18" /> {{ __('Pipeline Comercial') }} <span class="count">{{ $total }}</span></h2>
            <div class="pl-seg" role="group" aria-label="{{ __('Alcance') }}">
                <button type="button" wire:click="$set('scope', 'mine')" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}">{{ __('Mis leads') }}</button>
                <button type="button" wire:click="$set('scope', 'team')" aria-pressed="{{ $scope === 'team' ? 'true' : 'false' }}">{{ __('Equipo') }}</button>
            </div>
            <div class="search pl-search">
                <x-ui.icon name="search" class="i16" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Buscar por nombre o correo…') }}" aria-label="{{ __('Buscar por nombre o correo') }}" maxlength="100">
            </div>
        </div>
        <div class="pl-filters">
            @if ($scope === 'team')
                <select class="fbtn" wire:model.live="advisor" aria-label="{{ __('Asesor responsable') }}">
                    <option value="">{{ __('Asesor responsable · todos') }}</option>
                    @foreach ($advisors as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            @endif
            <select class="fbtn" wire:model.live="program" aria-label="{{ __('Programa') }}">
                <option value="">{{ __('Programa · todos') }}</option>
                @foreach ($programs as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
            <select class="fbtn" wire:model.live="product" aria-label="{{ __('Tipo de producto') }}">
                <option value="">{{ __('Producto · todos') }}</option>
                @foreach ($products as $code)
                    <option value="{{ $code }}">{{ (new \Modules\Crm\Models\Lead(['product_type' => $code]))->productLabel() }}</option>
                @endforeach
            </select>
            <select class="fbtn" wire:model.live="interest" aria-label="{{ __('Nivel de interés') }}">
                <option value="">{{ __('Interés · todos') }}</option>
                @foreach ($interests as $i)
                    <option value="{{ $i->value }}">{{ __($i->label()) }}</option>
                @endforeach
            </select>
            <select class="fbtn" wire:model.live="period" aria-label="{{ __('Período de captación') }}">
                <option value="">{{ __('Período · siempre') }}</option>
                <option value="7">{{ __('Últimos 7 días') }}</option>
                <option value="30">{{ __('Últimos 30 días') }}</option>
                <option value="90">{{ __('Últimos 90 días') }}</option>
            </select>
            @if ($filtered)
                <button type="button" class="pl-clear" wire:click="clearFilters">{{ __('Quitar filtros') }}</button>
            @endif
        </div>
    </div>

    @if ($notice !== '')
        <div class="toast {{ $noticeError ? 'err' : 'ok' }}" role="status" aria-live="polite" style="margin:14px 0 0">
            <x-ui.icon name="{{ $noticeError ? 'alert-triangle' : 'check' }}" class="i15" /> {{ $notice }}
        </div>
    @endif

    <div class="pl-board"
         x-data="{
            tab: 'new', drag: null, over: null,
            start(e, id, from) { this.drag = { id, from }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(id)); },
            drop(to) { const d = this.drag; this.drag = null; this.over = null; if (! d || d.from === to) { return; } this.$wire.move(d.id, d.from, to); },
         }"
         wire:loading.class="pl-busy" wire:target="move">
        <div class="pl-tabs" role="tablist" aria-label="{{ __('Etapas') }}">
            @foreach ($columns as $value => $col)
                <button type="button" role="tab" @click="tab = '{{ $value }}'" :aria-selected="tab === '{{ $value }}' ? 'true' : 'false'">
                    {{ __($col['status']->label()) }} <span>{{ $col['total'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="pl-cols">
            @foreach ($columns as $value => $col)
                <section class="pl-col" wire:key="col-{{ $value }}" aria-label="{{ __($col['status']->label()) }}"
                         :class="{ 'pl-m-hide': tab !== '{{ $value }}', 'pl-over': over === '{{ $value }}' }"
                         @dragover.prevent="if (drag && drag.from !== '{{ $value }}') { over = '{{ $value }}' }"
                         @dragleave.self="if (over === '{{ $value }}') { over = null }"
                         @drop.prevent="drop('{{ $value }}')">
                    <div class="pl-col-h">
                        <span class="chip {{ $col['status']->badgeClass() }}">{{ __($col['status']->label()) }}</span>
                        <span class="count" title="{{ __('Total con los filtros actuales') }}">{{ $col['total'] }}</span>
                    </div>
                    <div class="pl-list">
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
                                     @if ($canMove) draggable="true" @dragstart="start($event, {{ $lead->id }}, '{{ $value }}')" @dragend="drag = null; over = null" :class="drag && drag.id === {{ $lead->id }} ? 'pl-dragging' : ''" @endif>
                                <div class="pl-top">
                                    <a class="pl-name" href="{{ route('crm.leads.show', $lead) }}">{{ $name }}</a>
                                    <span class="pl-int {{ $lead->interest_level->value }}" title="{{ __('Nivel de interés') }}">{{ __($lead->interest_level->label()) }}</span>
                                </div>
                                <div class="pl-line"><b>{{ $lead->program?->name ?? __('Sin programa') }}</b> · {{ $lead->productLabel() }}</div>
                                <div class="pl-line">{{ __('Asesor') }}: <b>{{ $owner }}</b> · {{ __('Origen') }}: {{ $lead->capturedByLabel() }}</div>

                                @if (in_array($value, $openStages, true))
                                    @if ($cal['overdue'] > 0)
                                        <div class="pl-flag overdue">{{ trans_choice('Seguimiento vencido|:n seguimientos vencidos', $cal['overdue'], ['n' => $cal['overdue']]) }}</div>
                                    @elseif ($next !== null && $next->starts_at->lte($now->copy()->addHours(48)))
                                        <div class="pl-flag soon">{{ __('Actividad próxima') }}</div>
                                    @elseif ($next === null)
                                        <div class="pl-flag none">{{ __('Sin próxima acción programada') }}</div>
                                    @endif
                                @endif

                                @if ($next !== null)
                                    <div class="pl-next">
                                        <x-ui.icon name="calendar" class="i12" />
                                        <a href="{{ route('crm.agenda.show', $next) }}" style="color:inherit;text-decoration:none">{{ $next->type->label() }} · {{ $next->localStart($tz)->format('d/m H:i') }}</a>
                                        @include('crm::livewire.agenda.partials.join', ['a' => $next, 'compact' => false])
                                    </div>
                                @endif
                                <div class="pl-line" style="font-size:11.5px">
                                    {{ __('Última actividad') }}: {{ $last->copy()->setTimezone($tz)->format('d/m/Y') }}
                                    @if ($cal['pending'] > 0) · {{ __('Pendientes: :n', ['n' => $cal['pending']]) }}@endif
                                </div>

                                <div class="pl-acts">
                                    <a href="{{ route('crm.leads.show', $lead) }}">{{ __('Ver lead') }}</a>
                                    <a href="{{ route('crm.agenda.create', ['lead' => $lead->id]) }}">{{ __('Programar actividad') }}</a>
                                    @if ($lead->status->isTerminal())
                                        <span class="pl-final">{{ __('Etapa final') }}</span>
                                    @elseif ($canMove)
                                        <select class="pl-stage" aria-label="{{ __('Cambiar etapa de :name', ['name' => $name]) }}"
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
                            <div class="pl-empty">{{ __('Sin leads en esta etapa.') }}</div>
                        @endforelse
                    </div>
                    @if ($col['cards']->count() < $col['total'])
                        <button type="button" class="pl-more" wire:click="loadMore('{{ $value }}')" wire:loading.attr="disabled">
                            {{ __('Cargar más (:n restantes)', ['n' => $col['total'] - $col['cards']->count()]) }}
                        </button>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</div>
