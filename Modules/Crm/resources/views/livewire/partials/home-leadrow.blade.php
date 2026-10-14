{{-- Fila de lead del inicio (x-ui.kit). Espera: $lead, $metaText, $corporate (map), $leadName (closure). --}}
<a class="ui-row" href="{{ route('crm.leads.show', $lead) }}" wire:key="hl-{{ $lead->id }}">
    <x-ui.avatar :name="$leadName($lead)" :seed="$lead->contact_id" />
    <div class="ui-row-main">
        <div class="ui-row-title ui-person-name">{{ $leadName($lead) }}</div>
        <div class="ui-row-meta">{{ $metaText }}</div>
    </div>
    <div class="ui-row-aside">
        @if (isset($corporate[(int) $lead->contact_id]))
            <x-ui.badge tone="gold" icon="building-2">{{ __('Empresa') }}</x-ui.badge>
        @endif
        <x-ui.lead-status :status="$lead->status" />
    </div>
</a>
