@php
    use Modules\FollowUp\Support\Presentation as P;
    $titles=['summary'=>'Resumen operativo','actions'=>'Seguimientos','drafts'=>'Borradores inteligentes','human'=>'Atención humana','history'=>'Historial y auditoría'];
@endphp
<div class="mca-page fu-shell">
    <x-ui.page-header :title="$titles[$section]" :subtitle="__('Seguimiento inteligente · La IA propone. Las políticas protegen. Tú decides.')" />
    @include('followup::partials.navigation', ['active'=>$section])
    @if($section === 'summary')
        <div class="fu-notice">{{ __('La aprobación prepara una acción. Los envíos necesitan activación independiente. Los indicadores reflejan registros locales, no una garantía de elegibilidad futura.') }}</div>
        <div class="fu-metrics" data-testid="followup-metrics">
            @foreach(['active'=>['Oportunidades activas','opportunities','open'],'high'=>['Prioridad alta','opportunities',''],'approval'=>['Pendientes de aprobación','actions','pending_approval'],'scheduled'=>['Seguimientos programados','actions','scheduled'],'human'=>['Intervenciones humanas','human',''],'blocked'=>['Seguimientos bloqueados','actions','suppressed']] as $key=>$metric)
                <a class="fu-metric" href="{{ $metric[1] === 'opportunities' ? route('followup.opportunities', $key === 'high' ? ['prioridad'=>'high'] : ['estado'=>$metric[2]]) : route('followup.dashboard', match ($key) { 'approval'=>['vista'=>'actions','aprobacion'=>1], 'blocked'=>['vista'=>'actions','bloqueadas'=>1], default=>['vista'=>$metric[1],'estado'=>$metric[2]] }) }}"><span>{{ $metric[0] }}</span><strong data-testid="metric-{{ $key }}">{{ $counts[$key] }}</strong><span>Consultar →</span></a>
            @endforeach
        </div>
        <div class="fu-columns">
            <section class="ui-card"><h2>Oportunidades recientes</h2>
                @forelse($recent as $op)<div class="fu-item"><div><a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$op->id]) }}">{{ $op->socialConversation?->contact_name ?: 'Prospecto #'.$op->id }}</a><p class="fu-muted">{{ $op->bot?->assistant_name ?: $op->bot?->name }} · {{ $op->lead?->program?->name_es ?: 'Interés por precisar' }}</p></div><x-ui.badge :tone="P::tone($op->priority)">{{ P::label($op->priority) }}</x-ui.badge></div>@empty<div class="fu-empty">Todavía no hay oportunidades. Revisa la configuración y las conversaciones elegibles.</div>@endforelse
            </section>
            <section class="ui-card"><h2>Próximos pasos y decisiones</h2>
                @forelse($upcoming as $action)<div class="fu-item"><div><a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$action->follow_up_opportunity_id]) }}">{{ $action->opportunity?->socialConversation?->contact_name ?: 'Seguimiento #'.$action->id }}</a><p class="fu-muted">{{ $action->scheduled_at?->userTz()->format('d/m/Y H:i') ?: 'Sin fecha prevista' }}</p></div><x-ui.badge :tone="P::tone($action->status)">{{ P::label($action->status) }}</x-ui.badge></div>@empty<div class="fu-empty">No hay seguimientos que requieran decisión.</div>@endforelse
            </section>
        </div>
    @else
        <section class="ui-card">
            <div class="fu-filters">
                @if($section !== 'history')<label>Buscar prospecto<input class="ui-input" wire:model.live.debounce.350ms="search" type="search" maxlength="120" placeholder="Nombre o identificador"></label>@endif
                <label>Oportunidad<input class="ui-input" wire:model.live.debounce.350ms="opportunity" type="number" min="1" placeholder="Todas"></label>
                @if($section === 'history')<label>Actor<input class="ui-input" wire:model.live.debounce.350ms="actor" placeholder="Nombre o system" maxlength="120"></label><label>Tipo de evento<input class="ui-input" wire:model.live.debounce.350ms="eventType" placeholder="Ej.: action.approved" maxlength="48"></label>@else
                    <label>Estado<select class="ui-input" wire:model.live="status"><option value="">Todos</option>@foreach($section === 'drafts' ? ['queued','generating','review','no_contact','invalid','discarded','expired','error'] : ($section === 'human' ? ['paused','open'] : \Modules\FollowUp\Models\FollowUpAction::STATUSES) as $state)<option value="{{ $state }}">{{ P::label($state) }}</option>@endforeach</select></label>
                    <label>Canal<select class="ui-input" wire:model.live="channel"><option value="">Todos</option>@foreach(['whatsapp','instagram','messenger'] as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</select></label>
                    <label>Asesor<select class="ui-input" wire:model.live="bot"><option value="">Todos</option>@foreach($bots as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->assistant_name ?: $advisor->name }}</option>@endforeach</select></label>
                @endif
                <label>Desde<input class="ui-input" type="date" wire:model.live="from"></label><label>Hasta<input class="ui-input" type="date" wire:model.live="to"></label>
                <x-ui.button variant="ghost" wire:click="clearFilters">Limpiar filtros</x-ui.button>
            </div>
            @if(in_array($section,['actions','drafts']))<label><input type="checkbox" wire:model.live="approvalOnly"> Solo pendientes de aprobación</label><label><input type="checkbox" wire:model.live="blockedOnly"> Solo bloqueos de política</label>@endif
            <table class="ui-table fu-table"><thead><tr>
                @if($section === 'history')<th>Fecha / actor</th><th>Evento</th><th>Motivo / resultado</th><th>Oportunidad</th>
                @elseif($section === 'human')<th>Prospecto</th><th>Responsable / motivo</th><th>Actividad</th><th>Estado</th>
                @else<th>Prospecto / asesor</th><th>Estado</th><th>{{ $section === 'drafts' ? 'Borrador' : 'Programación / política' }}</th><th>Revisar</th>@endif
            </tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="{{ $section }}-{{ $row->id }}">
                    @if($section === 'history')
                        <td data-label="Fecha">{{ $row->occurred_at?->userTz()->format('d/m/Y H:i') }}<p class="fu-muted">{{ $row->actor?->name ?: 'Sistema' }}</p></td><td data-label="Evento">{{ P::label($row->event_type) }}</td><td data-label="Resultado">{{ P::label($row->reason_code) }}<p class="fu-muted">{{ P::label($row->safe_data['result'] ?? $row->policy_status) }}</p></td><td>@if($row->follow_up_opportunity_id)<a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$row->follow_up_opportunity_id]) }}">Oportunidad #{{ $row->follow_up_opportunity_id }}</a>@else Institucional @endif</td>
                    @elseif($section === 'human')
                        <td><a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$row->id]) }}">{{ $row->socialConversation?->contact_name ?: 'Prospecto #'.$row->id }}</a><p><x-ui.badge :tone="P::tone($row->priority)">{{ P::label($row->priority) }}</x-ui.badge></p></td><td data-label="Responsable">{{ $row->commercialOwner?->name ?: 'Sin responsable válido' }}<p class="fu-muted">{{ P::label($row->commercial_recommendation['reason'] ?? null) }}</p></td><td data-label="Actividad">@if($row->commercialActivity) @can('view',$row->commercialActivity)<a class="ui-link" href="{{ route('crm.agenda.show',$row->commercial_activity_id) }}">{{ $row->commercialActivity->title }}</a>@endcan<p>{{ $row->commercialActivity->starts_at?->userTz()->format('d/m/Y H:i') }}</p>@if($row->commercialActivity->isScheduled() && $row->commercialActivity->starts_at->isPast())<x-ui.badge tone="warning">Vencida</x-ui.badge>@endif @else Pendiente de crear tarea @endif</td><td><x-ui.badge :tone="P::tone($row->status)">{{ P::label($row->status) }}</x-ui.badge><p class="fu-muted">{{ P::label($row->commercial_result['code'] ?? null) }}</p></td>
                    @else
                        <td><b>{{ $row->opportunity?->socialConversation?->contact_name ?: 'Prospecto #'.$row->follow_up_opportunity_id }}</b><p class="fu-muted">{{ $row->opportunity?->bot?->assistant_name ?: $row->opportunity?->bot?->name }} · {{ ucfirst($row->channel) }}</p></td><td data-label="Estado"><x-ui.badge :tone="P::tone($section === 'drafts' ? $row->draft_state : $row->status)">{{ $section === 'drafts' ? P::draftLabel($row) : P::label($row->status) }}</x-ui.badge>@if($row->status === 'delivery_unknown')<p class="fu-notice fu-warning">La entrega no está confirmada. No reenviar; requiere conciliación.</p>@endif</td>
                        <td data-label="Detalle">@if($section === 'drafts'){{ $row->draft_payload['commercial_objective'] ?? 'Sin objetivo disponible' }}<p class="fu-muted">Versión {{ $row->draft_version }} · {{ $row->draft_edited_at ? 'Editado por una persona' : 'Preparado por el asesor' }}</p><p class="fu-muted">Caduca: {{ $row->draft_expires_at?->userTz()->format('d/m/Y H:i') ?: 'No disponible' }}</p>@else{{ $row->scheduled_at?->userTz()->format('d/m/Y H:i') ?: 'Sin programar' }}<p class="fu-muted">{{ P::label($row->policy_status) }} · {{ P::label($row->block_reason ?: $row->policy_reason_code) }}</p>@endif</td><td><a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$row->follow_up_opportunity_id]) }}">Revisar oportunidad →</a></td>
                    @endif
                </tr>@empty<tr><td colspan="4"><div class="fu-empty">No hay resultados con estos filtros. Puedes limpiarlos o volver al resumen.</div></td></tr>@endforelse
            </tbody></table>
            <x-ui.pager :paginator="$rows" />
        </section>
    @endif
</div>
