<div class="mca-page">
    @php
        $fullName = trim($contact->first_name.' '.$contact->last_name) ?: '—';
        $evTone = fn ($ev) => match ($ev->event_type) {
            'corporate_interest', 'corporate_contact', 'corporate_form', 'recontacted' => 'gold',
            'used_matcher', 'started_celia', 'contact_created' => 'blue',
            'viewed_program', 'viewed_certification', 'clicked_enrollment', 'program_interest' => 'success',
            'unresolved_question' => 'warning',
            default => 'neutral',
        };
    @endphp

    <div class="ui-record">
        <a href="{{ route('crm.contacts.index') }}" class="ui-back"><x-ui.icon name="chevron-left" /> {{ __('Volver a contactos') }}</a>

        {{-- CABECERA · identidad del contacto --}}
        <header class="ui-card ui-record-h">
            <x-ui.avatar :name="$fullName" :seed="$contact->id" size="xl" />
            <div class="ui-record-id">
                <h1 class="ui-record-title">{{ $fullName }}</h1>
                <div class="ui-record-meta">
                    <span><x-ui.icon name="mail" /> {!! str_replace('@', '@<wbr>', e($contact->email ?: '—')) !!}</span>
                    @if ($contact->phone)<span><x-ui.icon name="phone" /> {{ $contact->phone }}</span>@endif
                    @if ($contact->country)<span><x-ui.icon name="globe" /> {{ $contact->country }}</span>@endif
                </div>
            </div>
            <div class="ui-record-actions">
                <x-ui.badge tone="blue" icon="users">{{ $contact->leads->count() }} {{ $contact->leads->count() === 1 ? __('lead') : __('leads') }}</x-ui.badge>
                <x-ui.badge tone="teal" icon="message-circle">{{ $contact->conversations->count() }} {{ $contact->conversations->count() === 1 ? __('conversación') : __('conversaciones') }}</x-ui.badge>
            </div>
        </header>

        <div class="ui-record-grid">
            {{-- PRINCIPAL · relación comercial e historial --}}
            <div class="ui-record-main">
                <nav class="ui-subnav" aria-label="{{ __('Secciones de la ficha') }}">
                    <a href="#leads"><x-ui.icon name="users" /> {{ __('Leads') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $contact->leads->count() }}</span></a>
                    <a href="#interests"><x-ui.icon name="sparkles" /> {{ __('Intereses por programa') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $contact->programInterests->count() }}</span></a>
                    <a href="#conversations"><x-ui.icon name="message-circle" /> {{ __('Conversaciones') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $contact->conversations->count() }}</span></a>
                    <a href="#events"><x-ui.icon name="clock" /> {{ __('Historial de eventos') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $contact->events->count() }}</span></a>
                </nav>

                <section class="ui-card" id="leads" aria-labelledby="h-leads">
                    <div class="ui-card-h"><x-ui.icon name="users" class="ui-card-ic" /><h2 id="h-leads">{{ __('Leads') }}</h2></div>
                    <ul class="ui-list">
                        @forelse ($contact->leads as $lead)
                            <li wire:key="c-lead-{{ $lead->id }}">
                                <a href="{{ route('crm.leads.show', $lead) }}" class="ui-list-item">
                                    <span class="ui-list-main">
                                        <span class="ui-list-title">{{ optional($lead->program)->name_es ?: $lead->product_type }}</span>
                                        <span class="ui-person-line"><x-ui.lead-status :status="$lead->status" /> <span class="ui-list-meta">{{ __('Interés') }}: {{ __($lead->interest_level->label()) }}</span></span>
                                    </span>
                                    <span class="ui-list-aside ui-link">{{ __('Abrir') }} <x-ui.icon name="chevron-right" /></span>
                                </a>
                            </li>
                        @empty
                            <li class="ui-list-empty">{{ __('Sin leads.') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="ui-card" id="interests" aria-labelledby="h-interests">
                    <div class="ui-card-h"><x-ui.icon name="sparkles" class="ui-card-ic" /><h2 id="h-interests">{{ __('Intereses por programa') }}</h2></div>
                    <ul class="ui-list">
                        @forelse ($contact->programInterests as $interest)
                            <li wire:key="c-int-{{ $interest->id }}">
                                <div class="ui-list-item">
                                    <span class="ui-list-main">
                                        <span class="ui-list-title">{{ optional($interest->program)->name_es ?: ('#'.$interest->program_id) }}</span>
                                        <span class="ui-list-meta">{{ $interest->source }} · {{ $interest->created_at?->diffForHumans() }}</span>
                                    </span>
                                </div>
                            </li>
                        @empty
                            <li class="ui-list-empty">{{ __('Sin intereses registrados.') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="ui-card" id="conversations" aria-labelledby="h-convs">
                    <div class="ui-card-h"><x-ui.icon name="message-circle" class="ui-card-ic" /><h2 id="h-convs">{{ __('Conversaciones') }}</h2></div>
                    <ul class="ui-list">
                        @forelse ($contact->conversations as $conversation)
                            <li wire:key="c-conv-{{ $conversation->id }}">
                                <a href="{{ route('crm.conversations.show', $conversation) }}" class="ui-list-item">
                                    <span class="ui-tl-ic ui-tone--teal"><x-ui.icon name="message-circle" /></span>
                                    <span class="ui-list-main">
                                        <span class="ui-list-title">{{ $conversation->mode }} · {{ $conversation->language }}</span>
                                        <span class="ui-list-meta">{{ $conversation->last_activity_at?->diffForHumans() }}</span>
                                    </span>
                                    <span class="ui-list-aside ui-link">{{ __('Ver') }} <x-ui.icon name="chevron-right" /></span>
                                </a>
                            </li>
                        @empty
                            <li class="ui-list-empty">{{ __('Sin conversaciones.') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="ui-card" id="events" aria-labelledby="h-cevents">
                    <div class="ui-card-h"><x-ui.icon name="clock" class="ui-card-ic" /><h2 id="h-cevents">{{ __('Historial de eventos') }}</h2></div>
                    @if ($contact->events->isNotEmpty())
                        <ol class="ui-timeline">
                            @foreach ($contact->events as $event)
                                @php $detail = $event->detail(); @endphp
                                <li wire:key="c-ev-{{ $event->id }}">
                                    <span class="ui-tl-ic ui-tone--{{ $evTone($event) }}"><x-ui.icon name="{{ $event->icon() }}" /></span>
                                    <div class="ui-tl-body">
                                        {{ $event->label() }}@if ($detail)<span class="ui-tl-detail">: {{ $detail }}</span>@endif
                                        <div class="ui-tl-time">{{ $event->created_at?->diffForHumans() }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="ui-list-empty">{{ __('Sin eventos.') }}</p>
                    @endif
                </section>
            </div>

            {{-- LATERAL · datos del contacto --}}
            <aside class="ui-record-side">
                <section class="ui-card" aria-labelledby="h-cdata">
                    <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="h-cdata">{{ __('Datos del contacto') }}</h2></div>
                    <div class="ui-card-p">
                        <dl class="ui-meta ui-meta--spaced">
                            <dt>{{ __('Correo') }}</dt><dd>{!! str_replace('@', '@<wbr>', e($contact->email ?: '—')) !!}</dd>
                            <dt>{{ __('Teléfono') }}</dt><dd>@if ($contact->phone){{ $contact->phone }}@else<span class="t-nil">—</span>@endif</dd>
                            <dt>{{ __('País') }}</dt><dd>@if ($contact->country){{ $contact->country }}@else<span class="t-nil">—</span>@endif</dd>
                            <dt>{{ __('Idioma') }}</dt><dd>{{ $contact->preferred_language }}</dd>
                            <dt>{{ __('Consentimiento') }}</dt>
                            <dd>@if ($contact->consent_at){{ $contact->consent_at->toDateString() }} ({{ $contact->consent_source }})@else<span class="t-nil">{{ __('sin registrar') }}</span>@endif</dd>
                        </dl>
                    </div>
                </section>

            </aside>
        </div>
    </div>
</div>
