@php
    use Modules\Crm\Support\Agenda\CalendarGrid;

    $special = $overdue === '1' || $range !== '';
    $mode = $special ? 'list' : $view;
    $activeFilters = collect([$canFilterAdvisor ? $advisor : '', $type, $overdue === '1' ? '' : $status, $medium])->filter(fn ($v) => $v !== '')->count();
    $viewLabels = ['week' => __('Semana'), 'day' => __('Día'), 'month' => __('Mes'), 'list' => __('Lista')];
@endphp
<div class="mca-page">
    <x-ui.page-header class="ui-ph-stack" :title="__('Calendario')" :subtitle="__('Llamadas, reuniones y seguimientos con tus leads y contactos.')">
        <x-slot:actions>
            <x-ui.button icon="clock" :href="route('crm.agenda.availability')">{{ __('Mi disponibilidad') }}</x-ui.button>
            <x-ui.button icon="video" :href="route('crm.agenda.profile')">{{ __('Perfil de reuniones') }}</x-ui.button>
            @if ($canCreate)
                <x-ui.button variant="primary" icon="plus" :href="route('crm.agenda.create')">{{ __('Nueva actividad') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-card ui-cal" wire:loading.class="is-loading">
        {{-- Navegación temporal, periodo visible y vista --}}
        <div class="ui-cal-bar">
            @unless ($special)
                <div class="ui-cal-nav">
                    <x-ui.button size="sm" icon="chevron-left" wire:click="previous" :aria-label="__('Periodo anterior')" />
                    <x-ui.button size="sm" wire:click="today">{{ __('Hoy') }}</x-ui.button>
                    <x-ui.button size="sm" icon="chevron-right" wire:click="next" :aria-label="__('Periodo siguiente')" />
                </div>
            @endunless
            <h2 class="ui-cal-title" aria-live="polite">{{ \Illuminate\Support\Str::ucfirst($title) }}
                <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $activities->count() }}<span class="ui-sr"> {{ __('actividades') }}</span></span>
            </h2>
            <span class="sp"></span>
            <div class="ui-seg" role="group" aria-label="{{ __('Vista') }}">
                @foreach ($viewLabels as $v => $label)
                    <button type="button" wire:click="setView('{{ $v }}')" aria-pressed="{{ $view === $v && ! $special ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Filtros (en móvil, plegados tras «Filtros») --}}
        <div class="ui-toolbar">
            <x-ui.filters :active="$activeFilters">
                @if ($canFilterAdvisor)
                    <x-ui.select filter :active="$advisor !== ''" wire:model.live="advisor" :aria-label="__('Filtrar por asesor')">
                        <option value="">{{ __('Asesor · todos') }}</option>
                        @foreach ($advisors as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <x-ui.select filter :active="$type !== ''" wire:model.live="type" :aria-label="__('Filtrar por tipo')">
                    <option value="">{{ __('Tipo · todos') }}</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($overdue !== '1')
                    <x-ui.select filter :active="$status !== ''" wire:model.live="status" :aria-label="__('Filtrar por estado')">
                        <option value="">{{ __('Estado · todos') }}</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <x-ui.select filter :active="$medium !== ''" wire:model.live="medium" :aria-label="__('Filtrar por medio')">
                    <option value="">{{ __('Medio · todos') }}</option>
                    @foreach ($media as $m)
                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.filters>
        </div>

        @if ($special || $activeFilters > 0)
            <div class="ui-filter-note">
                <x-ui.icon name="filter" />
                {{ $overdue === '1' ? __('Solo actividades programadas cuya hora ya pasó.') : ($range !== '' ? __('Solo los próximos :n días.', ['n' => (int) $range]) : __('Filtros activos.')) }}
                <button type="button" class="ui-link ui-link--btn" wire:click="clearFilters">{{ __('Quitar filtros') }}</button>
            </div>
        @endif

        <div class="ui-cal-body">
            @if ($mode === 'list')
                {{-- Lista / vencidas / próximos días: agenda agrupada por día --}}
                @forelse ($byDay as $day => $items)
                    @include('crm::livewire.agenda.partials.day', ['d' => \Illuminate\Support\Carbon::parse($day, $tz), 'items' => $items, 'place' => [], 'empty' => ''])
                @empty
                    <div class="ui-empty">
                        <div class="ui-empty-ic"><x-ui.icon name="calendar" /></div>
                        <b>{{ $overdue === '1' ? __('No hay actividades vencidas.') : __('No hay actividades en este periodo.') }}</b>
                        @if ($canCreate && $overdue !== '1')
                            <a class="ui-link" href="{{ route('crm.agenda.create') }}">{{ __('Programar una actividad') }}</a>
                        @endif
                    </div>
                @endforelse
            @elseif ($mode === 'month')
                @php
                    $cells = array_slice($days, 0, intdiv(count($days), 7) * 7); // semanas completas
                    $weekdays = [__('Lun'), __('Mar'), __('Mié'), __('Jue'), __('Vie'), __('Sáb'), __('Dom')];
                @endphp
                <div class="ui-cal-month">
                    @foreach ($weekdays as $wd)
                        <div class="ui-cal-mh" aria-hidden="true">{{ $wd }}</div>
                    @endforeach
                    @foreach ($cells as $d)
                        @php
                            $key = $d->toDateString();
                            $items = $byDay[$key] ?? collect();
                        @endphp
                        <div @class(['ui-cal-cell', 'is-out' => $d->month !== $cursor->month, 'is-today' => $key === $todayLocal]) wire:key="m-{{ $key }}">
                            <button type="button" class="ui-cal-cell-n" wire:click="goTo('{{ $key }}')"
                                aria-label="{{ __('Ver el día :d', ['d' => $d->translatedFormat('l j \d\e F')]) }}{{ $items->isNotEmpty() ? ' · '.trans_choice(':n actividad|:n actividades', $items->count(), ['n' => $items->count()]) : '' }}">{{ $d->day }}</button>
                            @foreach ($items->take(3) as $a)
                                @include('crm::livewire.agenda.partials.chip', ['a' => $a])
                            @endforeach
                            @if ($items->count() > 3)
                                <button type="button" class="ui-cal-more" wire:click="goTo('{{ $key }}')">+{{ $items->count() - 3 }} {{ __('más') }}</button>
                            @endif
                            @if ($items->isNotEmpty())
                                <span class="ui-cal-dots" aria-hidden="true">
                                    @foreach ($items->take(3) as $a)<i class="ui-tone--{{ CalendarGrid::statusTone($a->status) }}"></i>@endforeach
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
                {{-- Móvil: bajo el mini-mes, la agenda del mes --}}
                <div class="ui-cal-month-agenda">
                    @php $monthDays = $byDay->filter(fn ($items, $day) => \Illuminate\Support\Carbon::parse($day, $tz)->month === $cursor->month); @endphp
                    @forelse ($monthDays as $day => $items)
                        @include('crm::livewire.agenda.partials.day', ['d' => \Illuminate\Support\Carbon::parse($day, $tz), 'items' => $items, 'place' => [], 'empty' => '', 'keyPrefix' => 'ma'])
                    @empty
                        <p class="ui-cal-free">{{ __('No hay actividades este mes.') }}</p>
                    @endforelse
                </div>
            @else
                {{-- Semana y día: rejilla horaria con espacio; agenda por días en tableta y móvil --}}
                @php
                    $gridDays = $mode === 'day' ? [$from->copy()] : $days;
                    $grid = CalendarGrid::build($gridDays, $byDay, $tz, now());
                    // fines de semana sin actividades: columna estrecha para dar ancho a los días con agenda
                    $tpl = collect($gridDays)->map(fn ($d) => $mode === 'week' && $d->isWeekend() && ($byDay[$d->toDateString()] ?? collect())->isEmpty() ? 'minmax(0,.5fr)' : 'minmax(0,1fr)')->implode(' ');
                @endphp
                <div @class(['ui-cal-grid', 'ui-cal-grid--day' => $mode === 'day']) style="--cols:{{ count($gridDays) }};--hours:{{ count($grid['hours']) }};--tpl:{{ $tpl }}">
                    <div class="ui-cal-gutter" aria-hidden="true">
                        <div class="ui-cal-gutter-h"></div>
                        <ol class="ui-cal-hours">
                            @foreach ($grid['hours'] as $i => $h)
                                <li style="--i:{{ $i }}">{{ $h }}</li>
                            @endforeach
                        </ol>
                    </div>
                    @foreach ($gridDays as $d)
                        @include('crm::livewire.agenda.partials.day', [
                            'd' => $d,
                            'items' => $byDay[$d->toDateString()] ?? collect(),
                            'place' => $grid['items'],
                            'clusters' => $grid['clusters'][$d->toDateString()] ?? [],
                            'now' => ($grid['now']['date'] ?? null) === $d->toDateString() ? $grid['now']['t'] : null,
                            'empty' => $mode === 'day' ? __('No hay actividades este día.') : __('Sin actividades'),
                        ])
                    @endforeach
                </div>
            @endif
        </div>

        <div class="ui-cal-foot">
            <ul class="ui-cal-legend" aria-label="{{ __('Estados') }}">
                @foreach ($statuses as $s)
                    <li class="ui-tone--{{ CalendarGrid::statusTone($s) }}"><i aria-hidden="true"></i>{{ $s->label() }}</li>
                @endforeach
            </ul>
            <span class="sp"></span>
            <span><x-ui.icon name="globe" class="ui-cal-foot-ic" /> {{ __('Horas mostradas en tu zona horaria: :tz', ['tz' => $tz]) }}</span>
        </div>
    </div>
</div>
