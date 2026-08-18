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
                    <div class="card kpi gold"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></div>
                    @break
                @case('soporte')
                    <div class="card kpi"><div class="top"><div class="kic b-blue"><x-ui.icon name="inbox" /></div></div><div><div class="num">{{ $counts['referredToMe'] }}</div><div class="lab">Casos referidos</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-warn"><x-ui.icon name="clock" /></div></div><div><div class="num">{{ $counts['referredPending'] }}</div><div class="lab">Pendientes</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div></div><div><div class="num">{{ $counts['conversationsToday'] }}</div><div class="lab">Conversaciones hoy</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-ok"><x-ui.icon name="graduation-cap" /></div></div><div><div class="num">{{ $counts['enrolledMonth'] }}</div><div class="lab">Matriculados (mes)</div></div></div>
                    <div class="card kpi gold"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></div>
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
                    <div class="card kpi gold"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></div>
                    @break
                @default {{-- admin / admisiones --}}
                    <div class="card kpi"><div class="top"><div class="kic b-blue"><x-ui.icon name="user-plus" /></div></div><div><div class="num">{{ $counts['new'] }}</div><div class="lab">Nuevos sin contactar</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-warn"><x-ui.icon name="clock" /></div></div><div><div class="num">{{ $counts['followup'] }}</div><div class="lab">En seguimiento</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-info"><x-ui.icon name="message-circle" /></div></div><div><div class="num">{{ $counts['conversationsToday'] }}</div><div class="lab">Conversaciones hoy</div></div></div>
                    <div class="card kpi"><div class="top"><div class="kic b-ok"><x-ui.icon name="graduation-cap" /></div></div><div><div class="num">{{ $counts['enrolledMonth'] }}</div><div class="lab">Matriculados (mes)</div></div></div>
                    <div class="card kpi gold"><div class="top"><div class="kic b-gold"><x-ui.icon name="building-2" /></div></div><div><div class="num">{{ $counts['corporate'] }}</div><div class="lab">Interés corporativo</div></div></div>
            @endswitch
        </div>

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

        {{-- Carril derecho reservado (solo Inicio): métricas/estadísticas futuras. --}}
        <aside class="home-rail">
            <div class="rail-card">
                <div class="rail-ic"><x-ui.icon name="bar-chart" /></div>
                <div class="rail-t">Métricas clave</div>
                <div class="rail-s">Próximamente este carril mostrará indicadores y estadísticas.</div>
            </div>
        </aside>
    </div>
</div>
