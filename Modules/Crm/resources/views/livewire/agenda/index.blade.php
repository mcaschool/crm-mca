<div class="crm-page mca-page">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    <div class="ag-wrap">
        <div class="panel">
            <div class="ag-bar">
                <h2><x-ui.icon name="calendar" class="i18" /> {{ $title }}</h2>
                <span class="count">{{ $activities->count() }}</span>
                <div class="sp"></div>

                @if ($overdue !== '1' && $range === '')
                    <div class="ag-nav">
                        <button type="button" class="ghost" wire:click="previous" aria-label="{{ __('Anterior') }}"><x-ui.icon name="chevron-left" class="i15" /></button>
                        <button type="button" class="ghost" wire:click="today">{{ __('Hoy') }}</button>
                        <button type="button" class="ghost" wire:click="next" aria-label="{{ __('Siguiente') }}"><x-ui.icon name="chevron-right" class="i15" /></button>
                    </div>
                @endif

                <div class="ag-seg" role="tablist" aria-label="{{ __('Vista') }}">
                    @foreach (['week' => __('Semana'), 'day' => __('Día'), 'month' => __('Mes'), 'list' => __('Lista')] as $v => $label)
                        <button type="button" class="{{ $view === $v && $overdue !== '1' && $range === '' ? 'on' : '' }}" wire:click="setView('{{ $v }}')">{{ $label }}</button>
                    @endforeach
                </div>

                @if ($canCreate)
                    <a href="{{ route('crm.agenda.create') }}" class="ghost solid"><x-ui.icon name="plus" class="i15" /> {{ __('Nueva actividad') }}</a>
                @endif
            </div>

            <div class="ag-filters">
                @if ($canFilterAdvisor)
                    <select class="fbtn" wire:model.live="advisor" aria-label="{{ __('Filtrar por asesor') }}">
                        <option value="">{{ __('Asesor · todos') }}</option>
                        @foreach ($advisors as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                @endif
                <select class="fbtn" wire:model.live="type" aria-label="{{ __('Filtrar por tipo') }}">
                    <option value="">{{ __('Tipo · todos') }}</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
                @if ($overdue !== '1')
                    <select class="fbtn" wire:model.live="status" aria-label="{{ __('Filtrar por estado') }}">
                        <option value="">{{ __('Estado · todos') }}</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                @endif
                <select class="fbtn" wire:model.live="medium" aria-label="{{ __('Filtrar por medio') }}">
                    <option value="">{{ __('Medio · todos') }}</option>
                    @foreach ($media as $m)
                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                    @endforeach
                </select>
                <div style="flex:1"></div>
                <a href="{{ route('crm.agenda.availability') }}" class="ghost"><x-ui.icon name="clock" class="i15" /> {{ __('Mi disponibilidad') }}</a>
                <a href="{{ route('crm.agenda.profile') }}" class="ghost"><x-ui.icon name="video" class="i15" /> {{ __('Perfil de reuniones') }}</a>
            </div>

            @if ($overdue === '1' || $range !== '' || $advisor !== '' || $type !== '' || $status !== '' || $medium !== '')
                <div class="ag-note">
                    <x-ui.icon name="filter" class="i14" /> {{ __('Filtros activos.') }}
                    <a href="#" wire:click.prevent="clearFilters">{{ __('Quitar filtros') }}</a>
                </div>
            @endif

            @if ($overdue === '1' || $range !== '' || $view === 'list' || $view === 'day')
                {{-- Día / lista --}}
                <div class="ag-list">
                    @php $groups = $view === 'day' && $overdue !== '1' && $range === '' ? collect([$from->toDateString() => $byDay[$from->toDateString()] ?? collect()]) : $byDay; @endphp
                    @forelse ($groups as $day => $items)
                        @if ($view !== 'day' || $overdue === '1' || $range !== '')
                            <div class="ag-group">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('l j \d\e F') }}</div>
                        @endif
                        @forelse ($items as $a)
                            @php $s = $a->starts_at->copy()->setTimezone($tz); $e = $a->ends_at->copy()->setTimezone($tz); @endphp
                            <div class="ag-row" wire:key="row-{{ $a->id }}">
                                <div class="when">{{ $s->format('H:i') }}<small>{{ __('hasta') }} {{ $e->format('H:i') }}</small></div>
                                <div class="what">
                                    <a href="{{ route('crm.agenda.show', $a) }}" class="t ag-open">{{ $a->title }}</a>
                                    <div class="s">
                                        <span><x-ui.icon name="user" class="i12" /> {{ $a->relatedName() ?? __('Sin lead/contacto') }}</span>
                                        <span><x-ui.icon name="user-cog" class="i12" /> {{ $a->assignee?->name ?? '—' }}</span>
                                        <span><x-ui.icon name="video" class="i12" /> {{ $a->medium->label() }}</span>
                                        <span>{{ $a->type->label() }}</span>
                                    </div>
                                </div>
                                <div class="st">
                                    <span class="ag-badge {{ $a->status->value }}">{{ $a->status->label() }}</span>
                                    @include('crm::livewire.agenda.partials.join', ['a' => $a, 'compact' => false])
                                </div>
                            </div>
                        @empty
                            <div class="empty">{{ __('No hay actividades este día.') }}</div>
                        @endforelse
                    @empty
                        <div class="empty">{{ __('No hay actividades en este periodo.') }}</div>
                    @endforelse
                </div>
            @elseif ($view === 'month')
                <div class="ag-month">
                    @foreach ([__('Lun'), __('Mar'), __('Mié'), __('Jue'), __('Vie'), __('Sáb'), __('Dom')] as $wd)
                        <div class="ag-mh">{{ $wd }}</div>
                    @endforeach
                    @foreach ($days as $d)
                        @php
                            $key = $d->toDateString();
                            $items = $byDay[$key] ?? collect();
                            $out = $d->month !== $cursor->month;
                        @endphp
                        <div class="ag-cell {{ $out ? 'out' : '' }} {{ $key === $todayLocal ? 'today' : '' }} {{ $items->isEmpty() ? 'emptyday' : '' }}" wire:key="m-{{ $key }}">
                            <span class="n" wire:click="goTo('{{ $key }}')">{{ $d->translatedFormat('D j') }}</span>
                            @foreach ($items->take(3) as $a)
                                @include('crm::livewire.agenda.partials.item', ['a' => $a, 'compact' => true, 'key' => 'm'])
                            @endforeach
                            @if ($items->count() > 3)
                                <button type="button" class="ag-more" wire:click="goTo('{{ $key }}')">+{{ $items->count() - 3 }} {{ __('más') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Semana --}}
                <div class="ag-week">
                    @foreach ($days as $d)
                        @php $key = $d->toDateString(); $items = $byDay[$key] ?? collect(); @endphp
                        <div class="ag-day" wire:key="w-{{ $key }}">
                            <div class="ag-dh {{ $key === $todayLocal ? 'today' : '' }}" wire:click="goTo('{{ $key }}')">
                                {{ $d->translatedFormat('D') }} <b>{{ $d->day }}</b>
                            </div>
                            <div class="ag-dbody">
                                @forelse ($items as $a)
                                    @include('crm::livewire.agenda.partials.item', ['a' => $a, 'key' => 'w'])
                                @empty
                                    <div class="ag-free">{{ __('Sin actividades') }}</div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <p style="font-size:12px;color:var(--muted);margin:0">{{ __('Horas mostradas en tu zona horaria: :tz', ['tz' => $tz]) }}</p>
    </div>
</div>
