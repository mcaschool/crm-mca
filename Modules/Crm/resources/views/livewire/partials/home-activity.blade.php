{{-- Actividad reciente del inicio (x-ui.kit). Espera: $activity (Collection<Event>). --}}
@forelse ($activity as $ev)
    @php
        $evTone = match ($ev->event_type) {
            'corporate_interest', 'corporate_contact', 'corporate_form', 'recontacted' => 'gold',
            'used_matcher', 'started_celia', 'contact_created' => 'blue',
            'viewed_program', 'viewed_certification', 'clicked_enrollment', 'program_interest' => 'success',
            'unresolved_question' => 'warning',
            default => 'teal',
        };
        $who = trim(($ev->contact->first_name ?? '').' '.($ev->contact->last_name ?? ''));
        $evDetail = $ev->detail();
    @endphp
    <div class="home-act" wire:key="ha-{{ $ev->id }}">
        <span class="home-act-ic ui-tone--{{ $evTone }}"><x-ui.icon name="{{ $ev->icon() }}" /></span>
        <div class="ui-row-main">
            <div class="home-act-t">@if ($who !== '')<b class="ui-person-name">{{ $who }}</b> @endif{{ $ev->label() }}@if ($evDetail) <span class="home-act-d">— {{ $evDetail }}</span>@endif</div>
            <div class="ui-row-meta">{{ $ev->created_at?->diffForHumans() }}</div>
        </div>
    </div>
@empty
    <div class="home-empty"><x-ui.icon name="activity" /> {{ __('Sin actividad reciente.') }}</div>
@endforelse
