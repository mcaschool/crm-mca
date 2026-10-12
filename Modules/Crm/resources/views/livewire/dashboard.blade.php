<div>
    <x-ui.home-styles />
    @php
        use Illuminate\Support\Str;

        $name = trim($user->name);
        $firstName = Str::of($name)->explode(' ')->first() ?: $name;

        $badge = fn (string $s) => match ($s) {
            'new' => 'st-nuevo', 'contacted' => 'st-cont', 'qualified' => 'st-seg',
            'enrolled' => 'st-matr', 'discarded' => 'st-desc', default => 'st-desc',
        };
        $personaLabel = match ($persona) {
            'admin' => 'Administrador', 'admisiones' => 'Admisiones', 'academico' => 'Académico',
            'marketing' => 'Marketing', 'soporte' => 'Soporte', default => 'Panel',
        };
        $leadInitials = function ($lead) {
            $f = $lead->contact->first_name ?? '';
            $l = $lead->contact->last_name ?? '';
            return mb_strtoupper(mb_substr($f, 0, 1).mb_substr($l, 0, 1)) ?: '—';
        };
        $leadName = fn ($lead) => trim(($lead->contact->first_name ?? '').' '.($lead->contact->last_name ?? '')) ?: '—';
        $newLeadMeta = fn ($lead) => trim(($lead->goal ?: '—').($lead->area ? ' · '.$lead->area : '').' · '.($lead->created_at?->diffForHumans() ?? ''));
    @endphp

    <div class="mca-home">
        <div class="home-main">
        {{-- Saludo compacto en una línea --}}
        <div class="hello">
            <h2>Hola, {{ $firstName }}</h2>
            <span class="role {{ $readOnly ? 'ro' : '' }}">{{ $personaLabel }}</span>
            <div class="sp"></div>

            @if ($canCreate)
                <a class="btn ghost" href="{{ route('crm.leads.create') }}"><x-ui.icon name="plus" /> Nuevo lead</a>
            @endif

            @switch($persona)
                @case('academico')
                @case('soporte')
                    <a class="btn primary" href="{{ route('crm.leads.index', ['mine' => 1]) }}"><x-ui.icon name="inbox" /> Mis referidos</a>
                    @break
                @case('marketing')
                    <a class="btn ghost" href="{{ route('crm.leads.index') }}"><x-ui.icon name="users" /> Ver e informar</a>
                    @break
                @default
                    <a class="btn primary" href="{{ route('crm.leads.index') }}"><x-ui.icon name="users" /> Ver leads</a>
            @endswitch
        </div>

        {{-- KPIs --}}
        <div class="grid kpis">
            @switch($persona)
                @case('academico')
                    <div class="card kpi"><div class="top"><div class="kic b-blue"><x-ui.icon name="user-plus" /></div></div><div><div class="num">{{ $counts['referredToMe'] }}</div><div class="lab">Referidos a ti</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-warn"><x-ui.icon name="clock" /></div></div><div><div class="num">{{ $counts['referredPending'] }}</div><div class="lab">Pendientes de matricular</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-ok"><x-ui.icon name="graduation-cap" /></div></div><div><div class="num">{{ $counts['enrolledMonth'] }}</div><div class="lab">Matriculados (mes)</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div></div><div><div class="num">{{ $counts['conversationsToday'] }}</div><div class="lab">Conversaciones hoy</div></div></div>
                    <a class="card kpi gold" href="{{ route('crm.leads.index', ['corporate' => 1]) }}" style="text-decoration:none;color:inherit" title="Ver los leads con interés corporativo"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div><x-ui.icon name="chevron-right" class="i14" style="color:var(--muted)" /></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></a>
                    @break
                @case('soporte')
                    <div class="card kpi"><div class="top"><div class="kic b-blue"><x-ui.icon name="inbox" /></div></div><div><div class="num">{{ $counts['referredToMe'] }}</div><div class="lab">Casos referidos</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-warn"><x-ui.icon name="clock" /></div></div><div><div class="num">{{ $counts['referredPending'] }}</div><div class="lab">Pendientes</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div></div><div><div class="num">{{ $counts['conversationsToday'] }}</div><div class="lab">Conversaciones hoy</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-ok"><x-ui.icon name="graduation-cap" /></div></div><div><div class="num">{{ $counts['enrolledMonth'] }}</div><div class="lab">Matriculados (mes)</div></div></div>
                    <a class="card kpi gold" href="{{ route('crm.leads.index', ['corporate' => 1]) }}" style="text-decoration:none;color:inherit" title="Ver los leads con interés corporativo"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div><x-ui.icon name="chevron-right" class="i14" style="color:var(--muted)" /></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></a>
                    @break
                @case('marketing')
                    @php
                        $deltaLeads = $counts['leadsPrevMonth'] > 0 ? (int) round(($counts['leadsMonth'] - $counts['leadsPrevMonth']) / $counts['leadsPrevMonth'] * 100) : null;
                        $deltaConv = $counts['conversationsPrevMonth'] > 0 ? (int) round(($counts['conversationsMonth'] - $counts['conversationsPrevMonth']) / $counts['conversationsPrevMonth'] * 100) : null;
                        $conversion = $counts['leadsMonth'] > 0 ? round($counts['enrolledMonth'] / $counts['leadsMonth'] * 100, 1) : 0;
                    @endphp
                    <div class="card kpi"><div class="top"><div class="kic b-blue"><x-ui.icon name="user-plus" /></div>@if ($deltaLeads !== null)<span class="trend" @style(['color:var(--mca-warn)' => $deltaLeads < 0])><x-ui.icon name="{{ $deltaLeads < 0 ? 'chevron-down' : 'chevron-up' }}" /> {{ $deltaLeads >= 0 ? '+' : '' }}{{ $deltaLeads }}%</span>@endif</div><div><div class="num">{{ $counts['leadsMonth'] }}</div><div class="lab">Leads (mes)</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div>@if ($deltaConv !== null)<span class="trend" @style(['color:var(--mca-warn)' => $deltaConv < 0])><x-ui.icon name="{{ $deltaConv < 0 ? 'chevron-down' : 'chevron-up' }}" /> {{ $deltaConv >= 0 ? '+' : '' }}{{ $deltaConv }}%</span>@endif</div><div><div class="num">{{ $counts['conversationsMonth'] }}</div><div class="lab">Conversaciones</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-ok"><x-ui.icon name="graduation-cap" /></div></div><div><div class="num">{{ $counts['enrolledMonth'] }}</div><div class="lab">Matriculados</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-warn"><x-ui.icon name="percent" /></div></div><div><div class="num">{{ $conversion }}%</div><div class="lab">Conversión</div></div></div>
                    <a class="card kpi gold" href="{{ route('crm.leads.index', ['corporate' => 1]) }}" style="text-decoration:none;color:inherit" title="Ver los leads con interés corporativo"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div><x-ui.icon name="chevron-right" class="i14" style="color:var(--muted)" /></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></a>
                    @break
                @default {{-- admin / admisiones: cada indicador abre su listado filtrado --}}
                    @foreach ([
                        'new' => ['Nuevos sin contactar', 'user-plus', 'b-blue'],
                        'overdue' => ['Tareas vencidas', 'alert-triangle', 'b-warn'],
                        'today' => ['Agenda de hoy', 'calendar', 'b-info'],
                        'next7' => ['Próximos 7 días', 'clock', 'b-blue'],
                        'followup' => ['En seguimiento', 'trending-up', 'b-warn'],
                        'enrolledMonth' => ['Matriculados del mes', 'graduation-cap', 'b-ok'],
                    ] as $k => [$label, $icon, $tone])
                        <a class="card kpi" href="{{ $kpis[$k]['url'] }}" style="text-decoration:none;color:inherit" data-kpi="{{ $k }}">
                            <div class="top"><div class="kic {{ $tone }}"><x-ui.icon name="{{ $icon }}" /></div><x-ui.icon name="chevron-right" class="i14" style="color:var(--muted);width:14px;height:14px" /></div>
                            <div><div class="num">{{ $kpis[$k]['n'] }}</div><div class="lab">{{ __($label) }}</div></div>
                        </a>
                    @endforeach
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div></div><div><div class="num">{{ $counts['conversationsToday'] }}</div><div class="lab">Conversaciones hoy</div></div></div>
                    <a class="card kpi gold" href="{{ route('crm.leads.index', ['corporate' => 1]) }}" style="text-decoration:none;color:inherit" title="Ver los leads con interés corporativo"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div><x-ui.icon name="chevron-right" class="i14" style="color:var(--muted)" /></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></a>
            @endswitch
        </div>

        @if ($agendaToday !== null)
            <div class="card ag-today">
                <div class="card-h">
                    <div class="hi"><x-ui.icon name="calendar" /></div>
                    <h3>{{ __('Mi agenda de hoy') }}</h3>
                    @if ($agendaToday->isNotEmpty())<span class="pill">{{ $agendaToday->count() }}</span>@endif
                    <a class="btn ghost" href="{{ route('crm.agenda.create') }}" style="padding:6px 10px"><x-ui.icon name="plus" /> {{ __('Nueva actividad') }}</a>
                </div>
                <div class="card-b">
                    @forelse ($agendaToday as $a)
                        @php
                            $s = $a->starts_at->copy()->setTimezone($agendaTz);
                            $join = $a->isScheduled() ? $a->joinUrl() : null;
                        @endphp
                        <div class="ag-trow" wire:key="today-{{ $a->id }}">
                            <div class="h">{{ $s->format('H:i') }}</div>
                            <div class="tx">
                                <a href="{{ route('crm.agenda.show', $a) }}" class="n">{{ $a->title }}</a>
                                <div class="m">{{ $a->relatedName() ?? __('Sin lead/contacto') }} · {{ $a->medium->label() }} · {{ $a->status->label() }}</div>
                            </div>
                            <div class="acts">
                                @if ($join)
                                    <a class="btn primary" href="{{ $join }}" target="_blank" rel="noopener noreferrer">{{ __('Unirse') }}</a>
                                @endif
                                @if ($a->isScheduled())
                                    <button type="button" class="btn ghost" wire:click="completeActivity({{ $a->id }})" wire:loading.attr="disabled">{{ __('Completar') }}</button>
                                    <a class="btn ghost" href="{{ route('crm.agenda.show', ['activity' => $a->id, 'panel' => 'reschedule']) }}">{{ __('Reprogramar') }}</a>
                                @endif
                                <a class="btn ghost" href="{{ route('crm.agenda.show', $a) }}">{{ __('Ver detalle') }}</a>
                            </div>
                        </div>
                    @empty
                        <div class="empty">{{ __('No tienes actividades para hoy.') }}</div>
                    @endforelse
                </div>
            </div>
            <style>
                .mca-home .ag-today .card-h .btn{margin-left:auto}
                .mca-home .ag-trow{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:11px}
                .mca-home .ag-trow:hover{background:#F7F9FC}
                .mca-home .ag-trow .h{font-weight:700;color:var(--mca-blue);width:48px;flex-shrink:0;font-size:14px}
                .mca-home .ag-trow .tx{flex:1;min-width:0}
                .mca-home .ag-trow .n{font-size:14px;font-weight:600;color:inherit;text-decoration:none}
                .mca-home .ag-trow .m{font-size:12px;color:var(--mca-ink-3);margin-top:1px}
                .mca-home .ag-trow .acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
                .mca-home .ag-trow .acts .btn{padding:6px 10px;font-size:12.5px}
                .mca-home .kpis:has(> [data-kpi]){grid-template-columns:repeat(4,1fr)}
                @media(max-width:1100px){.mca-home .kpis:has(> [data-kpi]){grid-template-columns:repeat(2,1fr)}}
                @media(max-width:760px){
                    .mca-home .kpis:has(> [data-kpi]){grid-template-columns:repeat(2,1fr)}
                    .mca-home .ag-trow{flex-wrap:wrap}
                    .mca-home .ag-trow .acts{width:100%;justify-content:flex-start;padding-left:60px}
                }
            </style>
        @endif

        {{-- Filas por persona --}}
        @switch($persona)
            @case('academico')
            @case('soporte')
                <div class="grid cols">
                    <div class="card">
                        <div class="card-h">
                            <div class="hi"><x-ui.icon name="inbox" /></div>
                            <h3>{{ $persona === 'soporte' ? 'Mis casos referidos' : 'Mis referidos' }}</h3>
                            @if ($counts['referredToMe'] > 0)<span class="pill">{{ $counts['referredToMe'] }}</span>@endif
                        </div>
                        <div class="card-b">
                            @forelse ($referrals as $lead)
                                @php
                                    $rm = $referralMeta[(int) $lead->contact_id] ?? null;
                                    $metaText = $rm ? 'Transferido por '.$rm['by'].' · '.($rm['at']?->diffForHumans() ?? '') : 'Referido a ti';
                                @endphp
                                @include('crm::livewire.partials.home-leadrow', ['lead' => $lead, 'metaText' => $metaText, 'badge' => $badge, 'corporate' => $corporate, 'leadInitials' => $leadInitials, 'leadName' => $leadName])
                            @empty
                                <div class="empty">No tienes referidos por ahora.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="activity" /></div><h3>Actividad reciente</h3></div>
                        <div class="card-b">@include('crm::livewire.partials.home-activity', ['activity' => $activity])</div>
                    </div>
                </div>
                @break

            @case('marketing')
                <div class="grid cols">
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="bar-chart" /></div><h3>Leads por área</h3><span class="pill">este mes</span></div>
                        <x-ui.chart-bars :rows="$leadsByArea" empty-message="Aún no hay leads por área." />
                    </div>
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="funnel" /></div><h3>Embudo de estados</h3></div>
                        <x-ui.funnel :segments="$funnel" />
                    </div>
                </div>
                @break

            @default {{-- admin / admisiones --}}
                <div class="grid cols">
                    <div class="card">
                        <div class="card-h">
                            <div class="hi"><x-ui.icon name="user-plus" /></div>
                            <h3>Leads nuevos sin contactar</h3>
                            @if ($counts['new'] > 0)<span class="pill">{{ $counts['new'] }}</span>@endif
                        </div>
                        <div class="card-b">
                            @forelse ($newLeads as $lead)
                                @include('crm::livewire.partials.home-leadrow', ['lead' => $lead, 'metaText' => $newLeadMeta($lead), 'badge' => $badge, 'corporate' => $corporate, 'leadInitials' => $leadInitials, 'leadName' => $leadName])
                            @empty
                                <div class="empty">No hay leads nuevos sin contactar.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="activity" /></div><h3>Actividad reciente</h3></div>
                        <div class="card-b">@include('crm::livewire.partials.home-activity', ['activity' => $activity])</div>
                    </div>
                </div>

                <div class="grid cols">
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="bar-chart" /></div><h3>Leads por área</h3><span class="pill">total</span></div>
                        <x-ui.chart-bars :rows="$leadsByArea" empty-message="Aún no hay leads por área." />
                    </div>
                    <div class="card">
                        <div class="card-h"><div class="hi"><x-ui.icon name="funnel" /></div><h3>Embudo de estados</h3></div>
                        <x-ui.funnel :segments="$funnel" />
                    </div>
                </div>
        @endswitch
        </div>{{-- /.home-main --}}
        {{-- El carril derecho reservado ahora lo aporta el layout común (para todas las
             páginas), no esta pantalla. --}}
    </div>
</div>
