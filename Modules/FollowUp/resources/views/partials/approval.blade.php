@php use Modules\FollowUp\Support\Presentation as P; @endphp
@if($approvalAction)
<div class="fu-modal-backdrop" x-data x-init="$nextTick(() => $refs.cancel.focus())" @keydown.escape.window="$wire.closeApproval()">
    <section class="fu-modal" role="dialog" aria-modal="true" aria-labelledby="approval-title" x-on:keydown.tab="const controls = Array.from($el.querySelectorAll('button,input,a')).filter(el => !el.disabled); if ($event.shiftKey && document.activeElement === controls[0]) { $event.preventDefault(); controls[controls.length-1].focus() } else if (!$event.shiftKey && document.activeElement === controls[controls.length-1]) { $event.preventDefault(); controls[0].focus() }">
        <h2 id="approval-title">Revisar y aprobar seguimiento</h2>
        <p><b>{{ $approvalAction->opportunity->socialConversation?->contact_name ?: 'Prospecto sin nombre' }}</b> · {{ ucfirst($approvalAction->channel) }}</p>
        <p class="fu-muted">Destinatario: {{ $approvalAction->opportunity->socialConversation?->contact_external_id }} · {{ P::label($approvalAction->purpose) }}</p>
        <p>Objetivo: {{ $approvalAction->draft_payload['commercial_objective'] ?? 'Revisar seguimiento comercial' }}</p>
        <p>Fecha prevista: {{ $approvalAction->scheduled_at?->userTz()->format('d/m/Y H:i') }} · Caduca: {{ $approvalAction->expires_at?->userTz()->format('d/m/Y H:i') }}</p>
        <p>Versión {{ $approvalVersion }} · Política {{ $approvalDecision?->version }}: {{ $approvalDecision?->explanation ?: 'No se pudo verificar la elegibilidad.' }}</p>
        <h3>Contenido exacto</h3><div class="fu-bubble">{{ $approvalContent['text'] ?? 'Contenido no verificable. Cierra esta ventana y revisa el borrador o la plantilla.' }}</div>
        <h3>Consentimiento aplicable</h3>
        @forelse($consents->where('channel',$approvalAction->channel)->where('purpose',$approvalAction->purpose) as $consent)<p>{{ P::label($consent->state) }} · {{ $consent->source ?: 'Sin procedencia' }} · {{ $consent->updated_at?->userTz()->format('d/m/Y H:i') }}</p>@empty<p>Sin consentimiento verificable registrado.</p>@endforelse
        @foreach($approvalAction->draft_payload['warnings'] ?? [] as $warning)<p class="fu-notice fu-warning">{{ $warning }}</p>@endforeach
        <p class="fu-notice">Esta operación registra la aprobación. No envía mensajes ni activa la ejecución automática.</p>
        @if($approvalContent && $approvalDecision?->allows() && hash_equals($approvalHash, $approvalContent['hash']) && $approvalAction->draft_version === $approvalVersion)
            <label><input type="checkbox" wire:model="approvalConfirmed"> He revisado destinatario, contenido, fecha, consentimiento y advertencias.</label>
            @error('approvalConfirmed')<p role="alert">Confirma la revisión antes de aprobar.</p>@enderror
            <div class="fu-actions"><x-ui.button x-ref="cancel" variant="ghost" wire:click="closeApproval">Volver a revisar</x-ui.button><x-ui.button wire:click="confirmExecution" wire:loading.attr="disabled">Confirmar aprobación</x-ui.button></div>
        @else
            <p class="fu-notice fu-warning">La aprobación está bloqueada o el contenido cambió. Revisa las condiciones vigentes.</p><x-ui.button x-ref="cancel" wire:click="closeApproval">Cerrar</x-ui.button>
        @endif
        @error('execution')<p role="alert">{{ P::label($message) }}</p>@enderror
    </section>
</div>
@endif
