@php
    $activeFilters = collect([$status, $priority, $provider, $bot])->filter(fn ($value) => $value !== '')->count();
    $statusLabels = ['open' => __('Abierta'), 'cancelled' => __('Cancelada'), 'suppressed' => __('Excluida')];
    $priorityLabels = ['high' => __('Alta'), 'medium' => __('Media'), 'low' => __('Baja')];
    $priorityTones = ['high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'];
@endphp
<div class="mca-page">
    <x-ui.page-header :title="__('Seguimiento inteligente')" :count="$opportunities->total()" :subtitle="__('Oportunidades detectadas por inactividad. Esta etapa no genera ni envía mensajes.')" />

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
                <thead><tr><th>{{ __('Prospecto') }}</th><th>{{ __('Asesor / canal') }}</th><th>{{ __('Clasificación') }}</th><th>{{ __('Prioridad') }}</th><th>{{ __('Última actividad') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                <tbody>
                @forelse ($opportunities as $opportunity)
                    <tr wire:key="followup-{{ $opportunity->id }}">
                        <td class="rt-primary">{{ $opportunity->socialConversation?->contact_name ?: __('Prospecto sin nombre') }}</td>
                        <td data-label="{{ __('Asesor / canal') }}"><b>{{ $opportunity->bot?->assistant_name ?: $opportunity->bot?->name }}</b><div class="t-3">{{ ucfirst($opportunity->provider) }}</div></td>
                        <td data-label="{{ __('Clasificación') }}">{{ __(str_replace('_', ' ', ucfirst($opportunity->classification))) }}</td>
                        <td data-label="{{ __('Prioridad') }}"><x-ui.badge :tone="$priorityTones[$opportunity->priority] ?? 'neutral'" dot>{{ $priorityLabels[$opportunity->priority] ?? $opportunity->priority }}</x-ui.badge><div class="t-3">{{ $opportunity->score }}/100</div></td>
                        <td data-label="{{ __('Última actividad') }}" class="t-2">{{ $opportunity->source_last_message_at?->userTz()->translatedFormat('d M Y · H:i') }}</td>
                        <td data-label="{{ __('Estado') }}">{{ $statusLabels[$opportunity->status] ?? $opportunity->status }}@if ($opportunity->cancellation_reason)<div class="t-3">{{ __(str_replace('_', ' ', $opportunity->cancellation_reason)) }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="rt-empty"><div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="funnel" /></div><b>{{ __('No hay oportunidades con estos filtros.') }}</b>{{ __('La detección registra únicamente conversaciones elegibles e inactivas.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$opportunities" />
    </div>
</div>
