@php
    use Modules\FollowUp\Support\Presentation as P;
    $activeFilters = collect([$status, $priority, $provider, $bot])->filter(fn ($value) => $value !== '')->count();
    $statusLabels = ['open' => __('Abierta'), 'cancelled' => __('Cancelada'), 'suppressed' => __('Excluida'), 'paused' => __('Pausada'), 'closed' => __('Cerrada')];
    $priorityLabels = ['high' => __('Alta'), 'medium' => __('Media'), 'low' => __('Baja')];
    $priorityTones = ['high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'];
@endphp
<div class="mca-page fu-shell">
    <x-ui.page-header :title="__('Seguimiento inteligente')" :count="$opportunities->total()" :subtitle="__('Oportunidades, borradores y continuidad comercial. Los envíos requieren autorización y activación independientes.')" />
    @include('followup::partials.navigation', ['active'=>'opportunities'])
    @if (session('followup_action')) <div class="ui-card" role="status">{{ session('followup_action') }}</div> @endif

    @if(!$selected)
    <div class="fu-overview" data-testid="followup-summary">
        <div><span>{{ __('Abiertas') }}</span><strong>{{ (int) ($summary['open'] ?? 0) }}</strong></div>
        <div><span>{{ __('Canceladas por respuesta') }}</span><strong>{{ (int) ($summary['cancelled'] ?? 0) }}</strong></div>
        <div><span>{{ __('Excluidas por reglas') }}</span><strong>{{ (int) ($summary['suppressed'] ?? 0) }}</strong></div>
    </div>

    <div class="ui-card ui-card--list">
        <div class="fu-filters">
            <label>Buscar prospecto<input class="ui-input" type="search" wire:model.live.debounce.350ms="search" maxlength="120" placeholder="Nombre o identificador"></label>
            <label>Programa<select class="ui-input" wire:model.live="program"><option value="">Todos</option>@foreach($programs as $item)<option value="{{ $item->id }}">{{ $item->name_es }}</option>@endforeach</select></label>
            <label>Detectada desde<input class="ui-input" type="date" wire:model.live="from"></label><label>Hasta<input class="ui-input" type="date" wire:model.live="to"></label>
            <label>Ordenar<select class="ui-input" wire:model.live="sort"><option value="priority">Prioridad</option><option value="recent">Más recientes</option><option value="next">Próxima evaluación</option></select></label>
        </div>
        <div class="fu-actions"><label><input type="checkbox" wire:model.live="approvalOnly"> Requiere aprobación</label><label><input type="checkbox" wire:model.live="humanOnly"> Atención humana</label><label><input type="checkbox" wire:model.live="consentOnly"> Consentimiento verificable para seguimiento</label></div>
        <div class="ui-toolbar">
            <x-ui.filters :active="$activeFilters">
                <x-ui.select filter :active="$status !== ''" wire:model.live="status" :aria-label="__('Filtrar por estado')">
                    <option value="">{{ __('Estado · todos') }}</option>
                    @foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </x-ui.select>
                <x-ui.select filter :active="$priority !== ''" wire:model.live="priority" :aria-label="__('Filtrar por prioridad')">
                    <option value="">{{ __('Prioridad · todas') }}</option>
                    @foreach ($priorityLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </x-ui.select>
                <x-ui.select filter :active="$provider !== ''" wire:model.live="provider" :aria-label="__('Filtrar por canal')">
                    <option value="">{{ __('Canal · todos') }}</option>
                    <option value="whatsapp">WhatsApp</option><option value="instagram">Instagram</option><option value="messenger">Messenger</option>
                </x-ui.select>
                <x-ui.select filter :active="$bot !== ''" wire:model.live="bot" :aria-label="__('Filtrar por asesor')">
                    <option value="">{{ __('Asesor · todos') }}</option>
                    @foreach ($bots as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->assistant_name ?: $advisor->name }}</option>@endforeach
                </x-ui.select>
                @if ($activeFilters > 0)<x-ui.button variant="ghost" icon="x" wire:click="clearFilters">{{ __('Limpiar') }}</x-ui.button>@endif
            </x-ui.filters>
        </div>

        <div class="mca-table-scroll">
            <table class="ui-table ui-table--stack-aside fu-table">
                <thead><tr><th>{{ __('Prospecto') }}</th><th>{{ __('Asesor / canal') }}</th><th>{{ __('Clasificación') }}</th><th>{{ __('Prioridad') }}</th><th>{{ __('Última actividad') }}</th><th>{{ __('Estado') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($opportunities as $opportunity)
                    <tr wire:key="followup-{{ $opportunity->id }}">
                        <td class="rt-primary">{{ $opportunity->socialConversation?->contact_name ?: __('Prospecto sin nombre') }}<p class="fu-muted">{{ $opportunity->lead?->program?->name_es ?: 'Programa por precisar' }}</p><p class="fu-muted">{{ $opportunity->commercialOwner?->name ?: 'Sin responsable' }}</p></td>
                        <td data-label="{{ __('Asesor / canal') }}"><b>{{ $opportunity->bot?->assistant_name ?: $opportunity->bot?->name }}</b><div class="t-3">{{ ucfirst($opportunity->provider) }}</div></td>
                        <td data-label="{{ __('Clasificación') }}">{{ P::label($opportunity->classification) }}<p class="fu-muted">{{ P::label($opportunity->lead?->status?->value) }} · Señal automática</p></td>
                        <td data-label="{{ __('Prioridad') }}"><x-ui.badge :tone="$priorityTones[$opportunity->priority] ?? 'neutral'" dot>{{ $priorityLabels[$opportunity->priority] ?? $opportunity->priority }}</x-ui.badge><div class="t-3">{{ $opportunity->score }}/100</div></td>
                        <td data-label="{{ __('Última actividad') }}" class="t-2">{{ $opportunity->source_last_message_at?->userTz()->translatedFormat('d M Y · H:i') }}<p class="fu-muted">Próxima revisión: {{ $opportunity->next_evaluation_at?->userTz()->format('d/m H:i') ?: 'Sin fecha' }}</p></td>
                        <td data-label="{{ __('Estado') }}">{{ $statusLabels[$opportunity->status] ?? $opportunity->status }}@if ($opportunity->cancellation_reason)<div class="t-3">{{ __(str_replace('_', ' ', $opportunity->cancellation_reason)) }}</div>@endif</td>
                        <td class="rt-actions"><x-ui.button variant="ghost" wire:click="selectOpportunity({{ $opportunity->id }})">{{ __('Revisar') }}</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rt-empty"><div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="funnel" /></div><b>{{ __('No hay oportunidades con estos filtros.') }}</b>{{ __('La detección registra únicamente conversaciones elegibles e inactivas.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$opportunities" />
    </div>
    @endif

    @if ($selected)
        <p><a class="ui-link" href="{{ route('followup.settings', ['asesor'=>$selected->bot_id]) }}">Revisar configuración del asesor y restricciones del canal</a></p>
        <div class="fu-actions"><a class="ui-link" href="{{ route('followup.opportunities') }}">← Volver a oportunidades</a><a class="ui-link" href="{{ route('social.inbox',['conversacion'=>$selected->social_conversation_id]) }}">Abrir conversación completa</a><a class="ui-link" href="{{ route('followup.dashboard',['vista'=>'history','oportunidad'=>$selected->id]) }}">Ver historial completo</a></div>
        <section class="ui-card">
            <h2>{{ $selected->socialConversation?->contact_name ?: 'Prospecto #'.$selected->id }}</h2>
            <p>{{ $selected->lead?->program?->name_es ?: 'Programa por precisar' }} · {{ ucfirst($selected->provider) }} · {{ $selected->bot?->assistant_name ?: $selected->bot?->name }}</p>
            <div class="fu-actions"><x-ui.badge :tone="P::tone($selected->priority)">Prioridad {{ P::label($selected->priority) }}</x-ui.badge><x-ui.badge :tone="P::tone($selected->status)">{{ P::label($selected->status) }}</x-ui.badge><span class="fu-muted">{{ $selected->commercialOwner?->name ?: 'Sin responsable válido' }}</span></div>
            <div class="fu-notice">{{ __('Las señales y recomendaciones automáticas son inferencias, no una matrícula ni una certeza comercial.') }}</div>
            <div class="fu-columns"><div><h3>Motivo y señales</h3><p>{{ P::label($selected->classification) }}</p>@foreach($selected->reason_codes as $reason)<p class="fu-muted">{{ P::label($reason) }}</p>@endforeach<h3>Siguiente paso recomendado</h3><p>{{ P::label($selected->commercial_recommendation['action'] ?? null) }}</p></div>
            <div><h3>Contexto reciente</h3>@forelse($contextMessages as $message)<div class="fu-bubble"><span class="fu-muted">{{ $message->direction === 'inbound' ? 'Prospecto' : 'Asesor' }} · {{ $message->created_at?->userTz()->format('d/m H:i') }}</span><p>{{ \Illuminate\Support\Str::limit($message->body, 450) }}</p></div>@empty<p class="fu-muted">No hay mensajes disponibles en el historial local.</p>@endforelse</div></div>
        </section>
        <section class="ui-card" data-testid="commercial-context">
            <h2>{{ __('Continuidad comercial') }}</h2>
            <p>{{ __('Estado') }}: {{ $statusLabels[$selected->status] ?? $selected->status }} · {{ $selected->commercial_close_reason }}</p>
            @if ($selected->contact) @can('view', $selected->contact)<a class="ui-link" href="{{ route('crm.contacts.show', $selected->contact_id) }}">{{ __('Abrir contacto') }} #{{ $selected->contact_id }}</a>@endcan @endif
            @if ($selected->lead) @can('view', $selected->lead)<a class="ui-link" href="{{ route('crm.leads.show', $selected->lead_id) }}">{{ __('Abrir Lead') }} #{{ $selected->lead_id }} · {{ $selected->lead->status->value }}</a>@endcan @endif
            <p>{{ __('Responsable') }}: {{ $commercialOwners->firstWhere('id', $selected->commercial_owner_id)?->name ?? __('Pendiente de asignación') }}</p>
            <p>{{ __('Recomendación') }}: {{ P::label($selected->commercial_recommendation['action'] ?? null) }} · {{ P::label($selected->commercial_recommendation['stage'] ?? 'keep') }} · {{ P::label($selected->commercial_recommendation['reason'] ?? null) }}</p>
            <p>{{ __('Resultado') }}: {{ P::label($selected->commercial_result['kind'] ?? null) }} · {{ P::label($selected->commercial_result['code'] ?? null) }}</p>
            <p>{{ __('Actualización comercial') }}: {{ $selected->commercial_updated_at?->userTz()->toDateTimeString() }}</p>
            <p>{{ __('Origen de la asociación') }}: {{ P::label($selected->commercial_link_source) }} · {{ $selected->commercial_linked_at?->userTz()->toDateTimeString() }}</p>
            <x-ui.button wire:click="evaluateCommercial">{{ __('Actualizar contexto comercial') }}</x-ui.button>
            @if (!$selected->commercial_close_reason)
                <x-ui.button wire:click="holdCommercial({{ $selected->commercial_version }}, {{ $selected->commercial_hold ? 'false' : 'true' }})">{{ $selected->commercial_hold ? __('Reanudar tras revisión humana') : __('Pausar seguimiento') }}</x-ui.button>
            @endif
            @foreach ($commercialActivities as $activity)
                <p><a class="ui-link" href="{{ route('crm.agenda.show', $activity->id) }}">{{ $activity->title }}</a> · {{ $activity->status->value }} · {{ $activity->starts_at?->userTz()->toDateTimeString() }}</p>
            @endforeach
            <details><summary>{{ __('Asociar un contacto verificado') }}</summary>
                <form wire:submit="linkCommercial({{ $selected->commercial_version }})">
                    <label>{{ __('ID del contacto') }}<input class="ui-input" type="number" min="1" wire:model="commercialContact" required></label>
                    <label>{{ __('ID del Lead (opcional)') }}<input class="ui-input" type="number" min="1" wire:model="commercialLead"></label>
                    <label>{{ __('ID de conversación CRM (opcional)') }}<input class="ui-input" type="number" min="1" wire:model="commercialConversation"></label>
                    <label>{{ __('Evidencia verificable de la relación') }}<textarea class="ui-input" wire:model="commercialEvidence" maxlength="500" required></textarea></label>
                    <x-ui.button type="submit">{{ __('Guardar asociación auditada') }}</x-ui.button>
                </form>
            </details>
            @if (!$selected->commercial_close_reason)
                <form wire:submit="createCommercialTask({{ $selected->commercial_version }})">
                    <h3>{{ __('Crear tarea interna en Agenda') }}</h3>
                    <label>{{ __('Responsable') }}<select class="ui-input" wire:model="commercialOwner" required><option value="">{{ __('Seleccionar') }}</option>@foreach ($commercialOwners as $owner)<option value="{{ $owner->id }}">{{ $owner->name }}</option>@endforeach</select></label>
                    <label>{{ __('Acción') }}<select class="ui-input" wire:model="commercialKind"><option value="task">{{ __('Seguimiento') }}</option><option value="admissions_task">{{ __('Revisar inscripción') }}</option><option value="call_proposal">{{ __('Coordinar llamada') }}</option><option value="meeting_proposal">{{ __('Proponer reunión') }}</option><option value="human_review">{{ __('Revisión comercial') }}</option></select></label>
                    <label>{{ __('Fecha y hora (UTC)') }}<input class="ui-input" type="datetime-local" wire:model="commercialStarts" required></label>
                    <p class="t-3">{{ __('Crea una tarea interna. No confirma citas, envía invitaciones ni modifica el Lead.') }}</p>
                    <x-ui.button type="submit">{{ __('Registrar tarea') }}</x-ui.button>
                </form>
            @endif
            @error('*') <p role="alert">{{ $message }}</p> @enderror
        </section>
        <section class="ui-card" @if($actions->contains(fn ($action) => in_array($action->draft_state,['queued','generating']))) wire:poll.visible.30s @endif>
            <h2>{{ __('Borradores de seguimiento') }}</h2>
            <x-ui.button wire:click="stopExecution">{{ __('Detener nuevos seguimientos de la institución') }}</x-ui.button>
            @foreach ($actions as $draftAction)
                <article wire:key="draft-{{ $draftAction->id }}-{{ $draftAction->draft_version }}">
                    <h3>Seguimiento #{{ $draftAction->id }} <x-ui.badge :tone="P::tone($draftAction->draft_state)">{{ P::draftLabel($draftAction) }}</x-ui.badge></h3><p class="fu-muted">Versión {{ $draftAction->draft_version }} · {{ $draftAction->draft_edited_at ? 'Editado por una persona' : 'Preparado por el asesor' }} · {{ P::label($draftAction->draft_error) }}</p>
                    <p>{{ __('Ejecución') }}: {{ P::label($draftAction->status) }} · {{ P::label($draftAction->execution_error) }}</p>
                    @if($draftAction->status === 'delivery_unknown')<p class="fu-notice fu-warning">Resultado desconocido: el proveedor puede haber recibido el mensaje. No reenviar. Requiere conciliación o revisión humana.</p>@endif
                    @if (isset($executionPreviews[$draftAction->id]) && $draftAction->draft_state === 'review' && in_array($draftAction->status, ['draft', 'pending_approval', 'approved', 'scheduled']))
                        <p>{{ __('Contenido definitivo para aprobar') }}: {{ $executionPreviews[$draftAction->id]['text'] }}</p>
                        <x-ui.button wire:click="reviewExecution({{ $draftAction->id }})" wire:loading.attr="disabled">{{ __('Revisar y aprobar esta versión') }}</x-ui.button>
                        <p class="t-3">{{ __('La ejecución requiere activación independiente y revalidación de políticas. Predeterminada: deshabilitada.') }}</p>
                    @endif
                    @if ($draftAction->draft_state === 'review' || $draftAction->draft_state === 'no_contact')
                        <p>{{ $draftAction->draft_payload['commercial_objective'] ?? '' }}</p>
                        <p style="white-space: pre-wrap">{{ $draftAction->draft_payload['draft_text'] ?? '' }}</p>
                        <p>{{ $draftAction->draft_payload['context_summary'] ?? '' }}</p>
                        <p>{{ P::label($draftAction->draft_payload['recommended_action'] ?? null) }} · {{ implode(', ', array_map(fn ($code) => P::label($code), $draftAction->draft_payload['reason_codes'] ?? [])) }}</p>
                        <p>{{ __('Fuentes') }} {{ implode(', ', $draftAction->draft_payload['source_references'] ?? []) }}</p>
                        <p>{{ implode(', ', $draftAction->draft_payload['warnings'] ?? []) }}</p>
                        <p>{{ __('Caduca') }} {{ $draftAction->draft_expires_at?->toDateTimeString() }}</p>
                        @if ($draftAction->draft_state === 'review' && !$draftAction->dispatch_at)
                            <x-ui.button wire:click="openDraft({{ $draftAction->id }})">{{ __('Editar') }}</x-ui.button>
                        @endif
                    @endif
                    @if ($selected->status === 'open' && in_array($draftAction->status, \Modules\FollowUp\Models\FollowUpAction::ACTIVE_STATUSES) && !in_array($draftAction->draft_state, ['queued', 'generating']))
                        <x-ui.button wire:click="generateDraft({{ $draftAction->id }}, {{ $draftAction->draft_version }})">{{ $draftAction->draft_version ? __('Regenerar borrador') : __('Generar borrador') }}</x-ui.button>
                    @endif
                    @if (!$draftAction->dispatch_at && in_array($draftAction->draft_state, ['queued', 'generating', 'review', 'no_contact']))
                        <x-ui.button wire:click="discardDraft({{ $draftAction->id }}, {{ $draftAction->draft_version }})">{{ __('Descartar') }}</x-ui.button>
                    @endif
                </article>
            @endforeach
            @if ($draftActionId)
                <form wire:submit="saveDraft">
                    <label>{{ __('Texto del borrador') }}<textarea class="ui-input" wire:model="draftText" maxlength="1500"></textarea></label>
                    <x-ui.button type="submit">{{ __('Guardar edición') }}</x-ui.button>
                </form>
            @endif
            @error('draft') <p role="alert">{{ $message }}</p> @enderror
            @error('execution') <p role="alert">{{ P::label($message) }}</p> @enderror
            <x-ui.pager :paginator="$actions" />
            <details>
                <summary>{{ __('Historial de borradores') }}</summary>
                @foreach ($draftEvents as $draftEvent)
                    <p>{{ $draftEvent->occurred_at }} · {{ $draftEvent->event_type }} · {{ $draftEvent->reason_code }}</p>
                @endforeach
            </details>
        </section>
        <div class="ui-grid ui-grid--2" id="followup-detail">
            <form wire:submit="recordConsent" class="ui-card">
                <h2>{{ __('Consentimiento vigente') }}</h2>
                <p class="t-3">{{ __('Sujeto exacto:') }} {{ $selected->contact_id ? __('contacto') : __('conversación') }} #{{ $selected->contact_id ?: $selected->social_conversation_id }} · {{ ucfirst($selected->provider) }}</p>
                <label>{{ __('Finalidad') }}<select class="ui-input" wire:model="actionPurpose"><option value="requested_follow_up">{{ __('Seguimiento solicitado') }}</option><option value="marketing">Marketing</option><option value="operational">{{ __('Operacional') }}</option></select></label>
                <label>{{ __('Estado') }}<select class="ui-input" wire:model="consentState"><option value="unknown">Sin verificar</option><option value="granted">Concedido</option><option value="denied">Denegado</option><option value="withdrawn">Retirado</option></select></label>
                <label>{{ __('Origen verificable') }}<input class="ui-input" wire:model="consentSource" maxlength="64"></label>
                <label>{{ __('Referencia de evidencia') }}<textarea class="ui-input" wire:model="consentEvidence" maxlength="2000"></textarea></label>
                @error('*') <div role="alert" class="t-3">{{ $message }}</div> @enderror
                <x-ui.button type="submit">{{ __('Registrar estado') }}</x-ui.button>
                <ul>@foreach($consents as $consent)<li>{{ P::label($consent->purpose) }} / {{ ucfirst($consent->channel) }}: <b>{{ P::label($consent->state) }}</b> · v{{ $consent->lock_version }}<p class="fu-muted">{{ $consent->source ?: "Procedencia sin verificar" }} · {{ $consent->updated_at?->userTz()->format("d/m/Y H:i") }}</p><p>{{ \Illuminate\Support\Str::limit($consent->evidence ?: "Sin evidencia registrada", 500) }}</p></li>@endforeach</ul>
            </form>
            <form wire:submit="proposeAction" class="ui-card">
                <h2>{{ __('Evaluar acción local') }}</h2>
                <p class="t-3">{{ __('Programar no sustituye la aprobación del contenido ni activa la ejecución.') }}</p>
                <label>{{ __('Programar para') }}<input class="ui-input" type="datetime-local" wire:model="scheduledAt"></label>
                <label>{{ __('Caduca') }}<input class="ui-input" type="datetime-local" wire:model="expiresAt"></label>
                @if ($selected->provider === 'whatsapp')<label>{{ __('Plantilla aprobada (opcional dentro de 24 h)') }}<select class="ui-input" wire:model="templateId"><option value="">{{ __('Sin plantilla') }}</option>@foreach($selected->channel?->whatsappTemplates?->where('status','APPROVED') ?? [] as $template)<option value="{{ $template->id }}">{{ $template->name }} · {{ $template->category }}</option>@endforeach</select></label>@endif
                <x-ui.button type="submit">{{ __('Evaluar y registrar') }}</x-ui.button>
            </form>
        </div>
        <div class="ui-card ui-card--list">
            <h2>{{ __('Acciones y decisiones') }}</h2>
            <div class="mca-table-scroll"><table class="ui-table fu-table"><thead><tr><th>{{ __('Estado') }}</th><th>{{ __('Política') }}</th><th>{{ __('Motivo') }}</th><th>{{ __('Programación') }}</th><th>{{ __('Intento') }}</th><th>{{ __('Controles locales') }}</th></tr></thead><tbody>
            @forelse($actions as $action)<tr><td>{{ P::label($action->status) }}</td><td>{{ P::label($action->policy_status) }} · {{ $action->policy_version }}</td><td>{{ P::label($action->policy_reason_code) }}</td><td>{{ $action->scheduled_at?->userTz()->translatedFormat('d M Y · H:i') }}</td><td>{{ $action->attempt_number }} ({{ __('ciclo') }} {{ $action->cycle_attempt_number }})</td><td>
                @if($action->status === 'pending_approval')<span class="fu-muted">Revisa el contenido en Borradores para aprobar.</span>@endif
                @if(in_array($action->status,['pending_approval','approved','scheduled','paused']) && !$action->dispatch_at)<details><summary>Posponer</summary><label>Nueva fecha (UTC)<input class="ui-input" type="datetime-local" wire:model="postponeAt"></label><x-ui.button wire:click="postponeAction({{ $action->id }}, {{ $action->eligibility_version }})">Guardar nueva fecha</x-ui.button>@error('postponeAt')<p role="alert">{{ $message }}</p>@enderror</details>@endif
                @if(in_array($action->status,['scheduled','approved']))<button class="ui-link" type="button" wire:click="pauseAction({{ $action->id }})">{{ __('Pausar') }}</button>@endif
                @if(in_array($action->status,\Modules\FollowUp\Models\FollowUpAction::ACTIVE_STATUSES))<button class="ui-link" type="button" wire:click="cancelAction({{ $action->id }})">{{ __('Cancelar') }}</button>@endif
            </td></tr>@empty<tr><td colspan="6">{{ __('Sin acciones locales.') }}</td></tr>@endforelse
            </tbody></table></div>
        </div>
        @include('followup::partials.approval')
    @endif
</div>
