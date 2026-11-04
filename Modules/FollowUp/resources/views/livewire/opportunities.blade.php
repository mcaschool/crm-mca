@php
    $activeFilters = collect([$status, $priority, $provider, $bot])->filter(fn ($value) => $value !== '')->count();
    $statusLabels = ['open' => __('Abierta'), 'cancelled' => __('Cancelada'), 'suppressed' => __('Excluida')];
    $priorityLabels = ['high' => __('Alta'), 'medium' => __('Media'), 'low' => __('Baja')];
    $priorityTones = ['high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'];
@endphp
<div class="mca-page">
    <x-ui.page-header :title="__('Seguimiento inteligente')" :count="$opportunities->total()" :subtitle="__('Decisiones deterministas y acciones locales. Este módulo no genera ni envía mensajes.')" />
    <p><a class="ui-link" href="{{ route('followup.settings') }}">{{ __('Configurar políticas institucionales y por asesor') }}</a></p>
    @if (session('followup_action')) <div class="ui-card" role="status">{{ session('followup_action') }}</div> @endif

    <div class="ui-grid ui-grid--3" data-testid="followup-summary">
        <div class="ui-card"><div class="t-3">{{ __('Abiertas') }}</div><strong class="ui-kpi">{{ (int) ($summary['open'] ?? 0) }}</strong></div>
        <div class="ui-card"><div class="t-3">{{ __('Canceladas por respuesta') }}</div><strong class="ui-kpi">{{ (int) ($summary['cancelled'] ?? 0) }}</strong></div>
        <div class="ui-card"><div class="t-3">{{ __('Excluidas por reglas') }}</div><strong class="ui-kpi">{{ (int) ($summary['suppressed'] ?? 0) }}</strong></div>
    </div>

    <div class="ui-card ui-card--list">
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
            <table class="ui-table ui-table--stack-aside">
                <thead><tr><th>{{ __('Prospecto') }}</th><th>{{ __('Asesor / canal') }}</th><th>{{ __('Clasificación') }}</th><th>{{ __('Prioridad') }}</th><th>{{ __('Última actividad') }}</th><th>{{ __('Estado') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($opportunities as $opportunity)
                    <tr wire:key="followup-{{ $opportunity->id }}">
                        <td class="rt-primary">{{ $opportunity->socialConversation?->contact_name ?: __('Prospecto sin nombre') }}</td>
                        <td data-label="{{ __('Asesor / canal') }}"><b>{{ $opportunity->bot?->assistant_name ?: $opportunity->bot?->name }}</b><div class="t-3">{{ ucfirst($opportunity->provider) }}</div></td>
                        <td data-label="{{ __('Clasificación') }}">{{ __(str_replace('_', ' ', ucfirst($opportunity->classification))) }}</td>
                        <td data-label="{{ __('Prioridad') }}"><x-ui.badge :tone="$priorityTones[$opportunity->priority] ?? 'neutral'" dot>{{ $priorityLabels[$opportunity->priority] ?? $opportunity->priority }}</x-ui.badge><div class="t-3">{{ $opportunity->score }}/100</div></td>
                        <td data-label="{{ __('Última actividad') }}" class="t-2">{{ $opportunity->source_last_message_at?->userTz()->translatedFormat('d M Y · H:i') }}</td>
                        <td data-label="{{ __('Estado') }}">{{ $statusLabels[$opportunity->status] ?? $opportunity->status }}@if ($opportunity->cancellation_reason)<div class="t-3">{{ __(str_replace('_', ' ', $opportunity->cancellation_reason)) }}</div>@endif</td>
                        <td><x-ui.button variant="ghost" wire:click="selectOpportunity({{ $opportunity->id }})">{{ __('Revisar') }}</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rt-empty"><div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="funnel" /></div><b>{{ __('No hay oportunidades con estos filtros.') }}</b>{{ __('La detección registra únicamente conversaciones elegibles e inactivas.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$opportunities" />
    </div>

    @if ($selected)
        <section class="ui-card" wire:poll.10s>
            <h2>{{ __('Borradores de seguimiento') }}</h2>
            <x-ui.button wire:click="stopExecution">{{ __('Detener nuevos seguimientos de la institución') }}</x-ui.button>
            @foreach ($actions as $draftAction)
                <article wire:key="draft-{{ $draftAction->id }}-{{ $draftAction->draft_version }}">
                    <p>#{{ $draftAction->id }} · {{ $draftAction->draft_state }} · v{{ $draftAction->draft_version }} · {{ $draftAction->draft_error }}</p>
                    <p>{{ __('Ejecución') }}: {{ $draftAction->status }} · {{ $draftAction->execution_error }} · {{ __('Mensaje') }} #{{ $draftAction->social_message_id }}</p>
                    @if (isset($executionPreviews[$draftAction->id]) && $draftAction->draft_state === 'review' && in_array($draftAction->status, ['draft', 'pending_approval', 'approved', 'scheduled']))
                        <p>{{ __('Contenido definitivo para aprobar') }}: {{ $executionPreviews[$draftAction->id]['text'] }}</p>
                        <x-ui.button wire:click="approveExecution({{ $draftAction->id }}, {{ $draftAction->draft_version }}, '{{ $executionPreviews[$draftAction->id]['hash'] }}')">{{ __('Aprobar esta versión y programar ejecución') }}</x-ui.button>
                        <p class="t-3">{{ __('La ejecución requiere activación independiente y revalidación de políticas. Predeterminada: deshabilitada.') }}</p>
                    @endif
                    @if ($draftAction->draft_state === 'review' || $draftAction->draft_state === 'no_contact')
                        <p>{{ $draftAction->draft_payload['commercial_objective'] ?? '' }}</p>
                        <p style="white-space: pre-wrap">{{ $draftAction->draft_payload['draft_text'] ?? '' }}</p>
                        <p>{{ $draftAction->draft_payload['context_summary'] ?? '' }}</p>
                        <p>{{ $draftAction->draft_payload['recommended_action'] ?? '' }} · {{ implode(', ', $draftAction->draft_payload['reason_codes'] ?? []) }}</p>
                        <p>{{ __('Fuentes') }} {{ implode(', ', $draftAction->draft_payload['source_references'] ?? []) }}</p>
                        <p>{{ implode(', ', $draftAction->draft_payload['warnings'] ?? []) }}</p>
                        <p>{{ __('Caduca') }} {{ $draftAction->draft_expires_at?->toDateTimeString() }}</p>
                        @if ($draftAction->draft_state === 'review' && !$draftAction->dispatch_at)
                            <x-ui.button wire:click="openDraft({{ $draftAction->id }})">{{ __('Editar') }}</x-ui.button>
                        @endif
                    @endif
                    @if (in_array($draftAction->status, \Modules\FollowUp\Models\FollowUpAction::ACTIVE_STATUSES) && !in_array($draftAction->draft_state, ['queued', 'generating']))
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
            @error('execution') <p role="alert">{{ $message }}</p> @enderror
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
                <label>{{ __('Estado') }}<select class="ui-input" wire:model="consentState"><option value="unknown">Unknown</option><option value="granted">Granted</option><option value="denied">Denied</option><option value="withdrawn">Withdrawn</option></select></label>
                <label>{{ __('Origen verificable') }}<input class="ui-input" wire:model="consentSource" maxlength="64"></label>
                <label>{{ __('Referencia de evidencia') }}<textarea class="ui-input" wire:model="consentEvidence" maxlength="2000"></textarea></label>
                @error('*') <div role="alert" class="t-3">{{ $message }}</div> @enderror
                <x-ui.button type="submit">{{ __('Registrar estado') }}</x-ui.button>
                <ul>@foreach($consents as $consent)<li>{{ $consent->purpose }} / {{ $consent->channel }}: <b>{{ $consent->state }}</b> · v{{ $consent->lock_version }}</li>@endforeach</ul>
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
            <div class="mca-table-scroll"><table class="ui-table"><thead><tr><th>{{ __('Estado') }}</th><th>{{ __('Política') }}</th><th>{{ __('Motivo') }}</th><th>{{ __('Programación') }}</th><th>{{ __('Intento') }}</th><th>{{ __('Controles locales') }}</th></tr></thead><tbody>
            @forelse($actions as $action)<tr><td>{{ $action->status }}</td><td>{{ $action->policy_status }} · {{ $action->policy_version }}</td><td>{{ $action->policy_reason_code }}</td><td>{{ $action->scheduled_at?->userTz()->translatedFormat('d M Y · H:i') }}</td><td>{{ $action->attempt_number }} ({{ __('ciclo') }} {{ $action->cycle_attempt_number }})</td><td>
                @if($action->status === 'pending_approval')<button class="ui-link" type="button" wire:click="approveAction({{ $action->id }})">{{ __('Aprobar') }}</button> <button class="ui-link" type="button" wire:click="scheduleAction({{ $action->id }})">{{ __('Aprobar y programar') }}</button>@endif
                @if(in_array($action->status,['scheduled','approved']))<button class="ui-link" type="button" wire:click="pauseAction({{ $action->id }})">{{ __('Pausar') }}</button>@endif
                @if(in_array($action->status,\Modules\FollowUp\Models\FollowUpAction::ACTIVE_STATUSES))<button class="ui-link" type="button" wire:click="cancelAction({{ $action->id }})">{{ __('Cancelar') }}</button>@endif
            </td></tr>@empty<tr><td colspan="6">{{ __('Sin acciones locales.') }}</td></tr>@endforelse
            </tbody></table></div>
        </div>
    @endif
</div>
