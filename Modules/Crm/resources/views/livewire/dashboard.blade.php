<div>
    <x-ui.home-styles />
    @php
        use Illuminate\Support\Str;

        $name = trim($user->name);
        $firstName = Str::of($name)->explode(' ')->first() ?: $name;

        $personaLabel = match ($persona) {
            'admin' => 'Administrador', 'admisiones' => 'Admisiones', 'academico' => 'Académico',
            'marketing' => 'Marketing', 'soporte' => 'Soporte', default => 'Panel',
        };
        $leadName = fn ($lead) => trim(($lead->contact->first_name ?? '').' '.($lead->contact->last_name ?? '')) ?: '—';
        $newLeadMeta = fn ($lead) => implode(' · ', array_filter([$lead->goal, $lead->area, $lead->created_at?->diffForHumans()])) ?: '—';
        $corpUrl = route('crm.leads.index', ['corporate' => 1]);
    @endphp

    <div class="mca-home mca-page">
        <x-ui.page-header :title="'Hola, '.$firstName" :subtitle="Str::ucfirst(now()->translatedFormat('l j \d\e F')).' · '.__('Resumen de tu actividad')">
            <x-slot:badge><x-ui.badge :tone="$readOnly ? 'neutral' : 'blue'">{{ $personaLabel }}</x-ui.badge></x-slot:badge>
            <x-slot:actions>
                @if ($canCreate)
                    <x-ui.button icon="plus" :href="route('crm.leads.create')">Nuevo lead</x-ui.button>
                @endif
                @switch($persona)
                    @case('academico')
                    @case('soporte')
                        <x-ui.button variant="primary" icon="inbox" :href="route('crm.leads.index', ['mine' => 1])">Mis referidos</x-ui.button>
                        @break
                    @case('marketing')
                        <x-ui.button icon="users" :href="route('crm.leads.index')">Ver e informar</x-ui.button>
                        @break
                    @default
                        <x-ui.button variant="primary" icon="users" :href="route('crm.leads.index')">Ver leads</x-ui.button>
                @endswitch
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Indicadores: cada uno con su tono; el dorado institucional solo en «Interés corporativo». --}}
        <div class="home-kpis">
            @switch($persona)
                @case('academico')
                    <x-ui.kpi label="Referidos a ti" :value="$counts['referredToMe']" icon="user-plus" tone="blue" />
                    <x-ui.kpi label="Pendientes de matricular" :value="$counts['referredPending']" icon="clock" tone="warning" />
                    <x-ui.kpi label="Matriculados (mes)" :value="$counts['enrolledMonth']" icon="graduation-cap" tone="success" />
                    <x-ui.kpi label="Conversaciones hoy" :value="$counts['conversationsToday']" icon="message-circle" tone="teal" />
                    <x-ui.kpi label="Interés corporativo" :value="$counts['corporate']" icon="building-2" tone="gold" :href="$corpUrl" accent title="Ver los leads con interés corporativo" />
                    @break
                @case('soporte')
                    <x-ui.kpi label="Casos referidos" :value="$counts['referredToMe']" icon="inbox" tone="blue" />
                    <x-ui.kpi label="Pendientes" :value="$counts['referredPending']" icon="clock" tone="warning" />
                    <x-ui.kpi label="Conversaciones hoy" :value="$counts['conversationsToday']" icon="message-circle" tone="teal" />
                    <x-ui.kpi label="Matriculados (mes)" :value="$counts['enrolledMonth']" icon="graduation-cap" tone="success" />
                    <x-ui.kpi label="Interés corporativo" :value="$counts['corporate']" icon="building-2" tone="gold" :href="$corpUrl" accent title="Ver los leads con interés corporativo" />
                    @break
                @case('marketing')
                    @php
                        $deltaLeads = $counts['leadsPrevMonth'] > 0 ? (int) round(($counts['leadsMonth'] - $counts['leadsPrevMonth']) / $counts['leadsPrevMonth'] * 100) : null;
                        $deltaConv = $counts['conversationsPrevMonth'] > 0 ? (int) round(($counts['conversationsMonth'] - $counts['conversationsPrevMonth']) / $counts['conversationsPrevMonth'] * 100) : null;
                        $conversion = $counts['leadsMonth'] > 0 ? round($counts['enrolledMonth'] / $counts['leadsMonth'] * 100, 1) : 0;
                    @endphp
                    <x-ui.kpi label="Leads (mes)" :value="$counts['leadsMonth']" icon="user-plus" tone="blue" :trend="$deltaLeads" />
                    <x-ui.kpi label="Conversaciones" :value="$counts['conversationsMonth']" icon="message-circle" tone="teal" :trend="$deltaConv" />
                    <x-ui.kpi label="Matriculados" :value="$counts['enrolledMonth']" icon="graduation-cap" tone="success" />
                    <x-ui.kpi label="Conversión" :value="$conversion.'%'" icon="percent" tone="warning" />
                    <x-ui.kpi label="Interés corporativo" :value="$counts['corporate']" icon="building-2" tone="gold" :href="$corpUrl" accent title="Ver los leads con interés corporativo" />
                    @break
                @default {{-- admin / admisiones: cada indicador abre su listado filtrado --}}
                    @foreach ([
                        'new' => ['Nuevos sin contactar', 'user-plus', 'blue'],
                        'overdue' => ['Tareas vencidas', 'alert-triangle', $kpis['overdue']['n'] > 0 ? 'danger' : 'neutral'],
                        'today' => ['Actividades de hoy', 'calendar', 'teal'],
                        'next7' => ['Próximos 7 días', 'clock', 'deep'],
                        'followup' => ['En seguimiento', 'trending-up', 'warning'],
                        'enrolledMonth' => ['Matriculados del mes', 'graduation-cap', 'success'],
                    ] as $k => [$label, $icon, $tone])
                        <x-ui.kpi :label="__($label)" :value="$kpis[$k]['n']" :icon="$icon" :tone="$tone" :href="$kpis[$k]['url']" data-kpi="{{ $k }}" />
                    @endforeach
                    <x-ui.kpi label="Conversaciones hoy" :value="$counts['conversationsToday']" icon="message-circle" tone="teal" />
                    <x-ui.kpi label="Interés corporativo" :value="$counts['corporate']" icon="building-2" tone="gold" :href="$corpUrl" accent title="Ver los leads con interés corporativo" />
            @endswitch
        </div>

        @if ($agendaToday !== null)
            <section class="ui-card home-agenda" aria-labelledby="h-agenda">
                <div class="ui-card-h">
                    <x-ui.icon name="calendar" class="ui-card-ic" />
                    <h2 id="h-agenda">{{ __('Mi calendario de hoy') }}</h2>
                    @if ($agendaToday->isNotEmpty())<x-ui.badge class="ui-badge--count">{{ $agendaToday->count() }}</x-ui.badge>@endif
                    <span class="sp"></span>
                    <x-ui.button size="sm" icon="plus" :href="route('crm.agenda.create')" :aria-label="__('Nueva actividad')"><span class="ui-hide-sm">{{ __('Nueva actividad') }}</span></x-ui.button>
                </div>
                <div class="ui-card-b">
                    @forelse ($agendaToday as $a)
                        @php
                            $s = $a->starts_at->copy()->setTimezone($agendaTz);
                            $join = $a->isScheduled() ? $a->joinUrl() : null;
                        @endphp
                        <div class="ag-row" wire:key="today-{{ $a->id }}">
                            <div class="ag-time">{{ $s->format('H:i') }}</div>
                            <div class="ui-row-main">
                                <a href="{{ route('crm.agenda.show', $a) }}" class="ui-row-title ag-title">{{ $a->title }}</a>
                                <div class="ui-row-meta">{{ $a->relatedName() ?? __('Sin lead/contacto') }} · {{ $a->medium->label() }} · {{ $a->status->label() }}</div>
                            </div>
                            <div class="ag-acts">
                                @if ($join)
                                    <x-ui.button variant="primary" size="sm" icon="video" :href="$join" target="_blank" rel="noopener noreferrer">{{ __('Unirse') }}</x-ui.button>
                                @endif
                                @if ($a->isScheduled())
                                    <x-ui.button size="sm" icon="check" wire:click="completeActivity({{ $a->id }})" wire:loading.attr="disabled">{{ __('Completar') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" :href="route('crm.agenda.show', ['activity' => $a->id, 'panel' => 'reschedule'])">{{ __('Reprogramar') }}</x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="ghost" :href="route('crm.agenda.show', $a)">{{ __('Ver detalle') }}</x-ui.button>
                            </div>
                        </div>
                    @empty
                        <div class="home-empty"><x-ui.icon name="calendar" /> {{ __('No tienes actividades para hoy.') }}</div>
                    @endforelse
                </div>
            </section>
        @endif

        {{-- Filas por persona --}}
        @switch($persona)
            @case('academico')
            @case('soporte')
                <div class="home-cols">
                    <section class="ui-card">
                        <div class="ui-card-h">
                            <x-ui.icon name="inbox" class="ui-card-ic" />
                            <h2>{{ $persona === 'soporte' ? 'Mis casos referidos' : 'Mis referidos' }}</h2>
                            @if ($counts['referredToMe'] > 0)<x-ui.badge class="ui-badge--count">{{ $counts['referredToMe'] }}</x-ui.badge>@endif
                            <span class="sp"></span>
                            <a class="ui-link" href="{{ route('crm.leads.index', ['mine' => 1]) }}">Ver todos <x-ui.icon name="chevron-right" /></a>
                        </div>
                        <div class="ui-card-b">
                            @forelse ($referrals as $lead)
                                @php
                                    $rm = $referralMeta[(int) $lead->contact_id] ?? null;
                                    $metaText = $rm ? 'Transferido por '.$rm['by'].' · '.($rm['at']?->diffForHumans() ?? '') : 'Referido a ti';
                                @endphp
                                @include('crm::livewire.partials.home-leadrow', ['lead' => $lead, 'metaText' => $metaText, 'corporate' => $corporate, 'leadName' => $leadName])
                            @empty
                                <div class="home-empty"><x-ui.icon name="inbox" /> No tienes referidos por ahora.</div>
                            @endforelse
                        </div>
                    </section>
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="activity" class="ui-card-ic" /><h2>Actividad reciente</h2></div>
                        <div class="ui-card-b">@include('crm::livewire.partials.home-activity', ['activity' => $activity])</div>
                    </section>
                </div>
                @break

            @case('marketing')
                <div class="home-cols">
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="bar-chart" class="ui-card-ic" /><h2>Leads por área</h2><span class="sp"></span><x-ui.badge>Este mes</x-ui.badge></div>
                        <x-ui.chart-bars :rows="$leadsByArea" empty-message="Aún no hay leads por área." />
                    </section>
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="funnel" class="ui-card-ic" /><h2>Embudo de estados</h2></div>
                        <x-ui.funnel :segments="$funnel" />
                    </section>
                </div>
                @break

            @default {{-- admin / admisiones --}}
                <div class="home-cols">
                    <section class="ui-card">
                        <div class="ui-card-h">
                            <x-ui.icon name="user-plus" class="ui-card-ic" />
                            <h2>Leads nuevos sin contactar</h2>
                            @if ($counts['new'] > 0)<x-ui.badge class="ui-badge--count">{{ $counts['new'] }}</x-ui.badge>@endif
                            <span class="sp"></span>
                            <a class="ui-link" href="{{ $kpis['new']['url'] }}">Ver todos <x-ui.icon name="chevron-right" /></a>
                        </div>
                        <div class="ui-card-b">
                            @forelse ($newLeads as $lead)
                                @include('crm::livewire.partials.home-leadrow', ['lead' => $lead, 'metaText' => $newLeadMeta($lead), 'corporate' => $corporate, 'leadName' => $leadName])
                            @empty
                                <div class="home-empty"><x-ui.icon name="check" /> No hay leads nuevos sin contactar.</div>
                            @endforelse
                        </div>
                    </section>
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="activity" class="ui-card-ic" /><h2>Actividad reciente</h2></div>
                        <div class="ui-card-b">@include('crm::livewire.partials.home-activity', ['activity' => $activity])</div>
                    </section>
                </div>

                <div class="home-cols">
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="bar-chart" class="ui-card-ic" /><h2>Leads por área</h2><span class="sp"></span><x-ui.badge>Total</x-ui.badge></div>
                        <x-ui.chart-bars :rows="$leadsByArea" empty-message="Aún no hay leads por área." />
                    </section>
                    <section class="ui-card">
                        <div class="ui-card-h"><x-ui.icon name="funnel" class="ui-card-ic" /><h2>Embudo de estados</h2></div>
                        <x-ui.funnel :segments="$funnel" />
                    </section>
                </div>
        @endswitch
    </div>
</div>
