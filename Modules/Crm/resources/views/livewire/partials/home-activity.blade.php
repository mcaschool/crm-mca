{{-- Actividad reciente (dashboard v4). Espera: $activity (Collection<Event>). --}}
@forelse ($activity as $ev)
    @php
        $evTone = match ($ev->event_type) {
            'corporate_interest', 'corporate_contact', 'corporate_form', 'recontacted' => 'b-gold',
            'used_matcher', 'started_celia', 'contact_created' => 'b-blue',
            'viewed_program', 'viewed_certification', 'clicked_enrollment', 'program_interest' => 'b-ok',
            'unresolved_question' => 'b-warn',
            default => 'b-info',
        };
        $who = trim(($ev->contact->first_name ?? '').' '.($ev->contact->last_name ?? ''));
        $evDetail = $ev->detail();
    @endphp
    <div class="act" wire:key="ha-{{ $ev->id }}">
        <span class="d {{ $evTone }}"><x-ui.icon name="{{ $ev->icon() }}" /></span>
        <div class="tx">
            <div class="t">@if ($who !== '')<b>{{ $who }}:</b> @endif{{ $ev->label() }}@if ($evDetail) <span class="mca-muted">— {{ $evDetail }}</span>@endif</div>
            <div class="ago">{{ $ev->created_at?->diffForHumans() }}</div>
        </div>
    </div>
@empty
    <div class="empty">Sin actividad reciente.</div>
@endforelse
