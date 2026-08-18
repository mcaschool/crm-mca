{{-- Actividad reciente (dashboard v4). Espera: $activity (Collection<Event>). --}}
@forelse ($activity as $ev)
    @php
        [$evIcon, $evTone, $evLabel] = match ($ev->event_type) {
            'corporate_interest' => ['building-2', 'b-gold', 'interés corporativo detectado'],
            'used_matcher' => ['sparkles', 'b-blue', 'usó el emparejador'],
            'viewed_program' => ['graduation-cap', 'b-ok', 'vio un programa'],
            'viewed_certification' => ['graduation-cap', 'b-ok', 'vio la certificación'],
            'started_celia' => ['message-circle', 'b-blue', 'inició conversación con Celia'],
            'clicked_enrollment' => ['graduation-cap', 'b-ok', 'fue a inscripciones'],
            'widget_opened' => ['message-circle', 'b-info', 'abrió el widget'],
            'contact_created' => ['user-plus', 'b-blue', 'nuevo lead'],
            'lead_transferred' => ['arrow-right-left', 'b-info', 'seguimiento transferido'],
            'unresolved_question' => ['activity', 'b-warn', 'pregunta no resuelta'],
            default => ['activity', 'b-info', $ev->event_type],
        };
        $who = trim(($ev->contact->first_name ?? '').' '.($ev->contact->last_name ?? ''));
    @endphp
    <div class="act" wire:key="ha-{{ $ev->id }}">
        <span class="d {{ $evTone }}"><x-ui.icon name="{{ $evIcon }}" /></span>
        <div class="tx">
            <div class="t">@if ($who !== '')<b>{{ $who }}:</b> @endif{{ $evLabel }}</div>
            <div class="ago">{{ $ev->created_at?->diffForHumans() }}</div>
        </div>
    </div>
@empty
    <div class="empty">Sin actividad reciente.</div>
@endforelse
