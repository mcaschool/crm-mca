{{-- Fila de lead (dashboard v4). Espera: $lead, $metaText, $badge (closure),
     $corporate (map), $leadInitials/$leadName (closures). --}}
<a class="lead" href="{{ route('crm.leads.show', $lead) }}" wire:key="hl-{{ $lead->id }}">
    <span class="av">{{ $leadInitials($lead) }}</span>
    <div class="tx">
        <div class="n">{{ $leadName($lead) }}</div>
        <div class="m">{{ $metaText }}</div>
    </div>
    <div class="rt">
        @if (isset($corporate[(int) $lead->contact_id]))
            <span class="tag emp"><x-ui.icon name="building-2" /> Empresa</span>
        @endif
        <span class="tag {{ $badge($lead->status->value) }}">{{ $lead->status->label() }}</span>
    </div>
</a>
