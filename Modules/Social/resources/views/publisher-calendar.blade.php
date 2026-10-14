@php
    $weekdays = [__('Lun'), __('Mar'), __('Mié'), __('Jue'), __('Vie'), __('Sáb'), __('Dom')];
    $time = fn ($p) => $p->referenceAt()?->copy()->setTimezone($tz)->format('H:i');
    $netLabel = fn ($n) => \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n] ?? $n;
@endphp
<div class="mca-page" wire:key="publisher-calendar">
    <x-ui.page-header :title="__('Publicador')" :subtitle="__('Calendario editorial: qué se publica y cuándo, en todas las redes.')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" :href="route('social.publisher')">{{ __('Nueva publicación') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @include('social::partials.publisher-tabs')

    @if ($notice)
        <div class="ui-alert ui-tone--{{ $noticeTone ?? 'success' }}" role="status"><x-ui.icon name="{{ $noticeTone === 'success' ? 'check' : 'alert-triangle' }}" /> {{ $notice }}</div>
    @endif

    <div class="ui-card ui-cal ui-pub-cal">
        <div class="ui-toolbar">
            <div class="ui-actions-row">
                <x-ui.button size="sm" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Mes anterior')" />
                <x-ui.button size="sm" wire:click="thisMonth">{{ __('Hoy') }}</x-ui.button>
                <x-ui.button size="sm" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Mes siguiente')" />
            </div>
            <h2 class="ui-pub-cal-title">{{ \Illuminate\Support\Str::ucfirst($cursor->translatedFormat('F Y')) }}
                <x-ui.badge tone="neutral" class="ui-badge--count">{{ $monthPosts->count() }}</x-ui.badge></h2>
            <span class="sp"></span>
            <div class="ui-seg" role="group" aria-label="{{ __('Vista') }}">
                <button type="button" wire:click="setMode('month')" aria-pressed="{{ $mode === 'month' ? 'true' : 'false' }}">{{ __('Mes') }}</button>
                <button type="button" wire:click="setMode('list')" aria-pressed="{{ $mode === 'list' ? 'true' : 'false' }}">{{ __('Agenda') }}</button>
            </div>
        </div>

        @if ($mode === 'month')
            <div class="ui-cal-month">
                @foreach ($weekdays as $wd)
                    <div class="ui-cal-mh" aria-hidden="true">{{ $wd }}</div>
                @endforeach
                @foreach ($days as $d)
                    @php $key = $d->toDateString(); $items = $byDay[$key] ?? collect(); @endphp
                    <div @class(['ui-cal-cell', 'is-out' => $d->month !== $cursor->month, 'is-today' => $key === $todayLocal]) wire:key="pc-{{ $key }}">
                        <span class="ui-cal-cell-n" aria-hidden="true">{{ $d->day }}</span>
                        @foreach ($items->take(3) as $p)
                            <button type="button" @class(['ui-cal-chip', 'ui-pub-chip', 'ui-tone--'.$p->statusTone(), 'is-cancelled' => $p->status === 'cancelled']) wire:click="openPost({{ $p->id }})"
                                title="{{ $time($p) }} · {{ $p->contentLabel() }} · {{ $p->statusLabel() }}">
                                <i aria-hidden="true"></i>
                                <time>{{ $time($p) }}</time>
                                <span class="ui-pub-chip-nets" aria-hidden="true">@foreach ($p->destinationNetworks() as $n)@include('social::partials.provider-icon', ['provider' => $n, 'size' => 12])@endforeach</span>
                                <span class="ui-pub-chip-t">{{ $p->contentLabel() }}</span>
                                <span class="ui-sr">, {{ collect($p->destinationNetworks())->map($netLabel)->implode(', ') }}, {{ $p->statusLabel() }}</span>
                            </button>
                        @endforeach
                        @if ($items->count() > 3)
                            <button type="button" class="ui-cal-more" wire:click="setMode('list')">+{{ $items->count() - 3 }} {{ __('más') }}</button>
                        @endif
                        @if ($items->isNotEmpty())
                            <span class="ui-cal-dots" aria-hidden="true">@foreach ($items->take(3) as $p)<i class="ui-tone--{{ $p->statusTone() }}"></i>@endforeach</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Agenda del mes (vista «Agenda» y, en móvil, bajo el mini-mes) --}}
        <div @class(['ui-pub-agenda', 'ui-cal-month-agenda' => $mode === 'month'])>
            @forelse ($monthPosts->groupBy(fn ($p) => $p->referenceAt()->copy()->setTimezone($tz)->toDateString()) as $day => $items)
                @php $dd = \Illuminate\Support\Carbon::parse($day, $tz); @endphp
                <section class="ui-pub-agenda-day" wire:key="pa-{{ $day }}">
                    <h3 @class(['ui-pub-agenda-h', 'is-today' => $day === $todayLocal])>{{ \Illuminate\Support\Str::ucfirst($dd->translatedFormat('l j \d\e F')) }}</h3>
                    <ul class="ui-list">
                        @foreach ($items as $p)
                            <li wire:key="pai-{{ $p->id }}">
                                <button type="button" class="ui-list-item ui-pub-row" wire:click="openPost({{ $p->id }})">
                                    <span class="ui-pub-agenda-time">{{ $time($p) }}</span>
                                    @include('social::partials.post-thumb', ['post' => $p])
                                    <span class="ui-list-main">
                                        <span class="ui-list-title">{{ $p->contentLabel() }} · {{ $p->caption ? \Illuminate\Support\Str::limit($p->caption, 80) : __('Sin descripción') }}</span>
                                        <span class="ui-pub-targets">@foreach ($p->destinationNetworks() as $n)<span>@include('social::partials.provider-icon', ['provider' => $n, 'size' => 14]) {{ $netLabel($n) }}</span>@endforeach</span>
                                    </span>
                                    <x-ui.badge :tone="$p->statusTone()" dot>{{ $p->statusLabel() }}</x-ui.badge>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="calendar" /></div><b>{{ __('Nada en este mes.') }}</b>{{ __('Las publicaciones programadas y publicadas aparecen en su día.') }}</div>
            @endforelse
        </div>
        <p class="ui-card-note"><x-ui.icon name="globe" /> {{ __('Horas mostradas en tu zona horaria: :tz', ['tz' => $tz]) }}</p>
    </div>

    @include('social::partials.post-detail')
</div>
