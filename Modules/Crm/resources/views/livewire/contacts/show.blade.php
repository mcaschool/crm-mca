<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <a href="{{ route('crm.contacts.index') }}" class="btn btn-ghost btn-sm" style="margin-bottom:14px">
            <x-ui.icon name="chevron-left" class="ic" style="width:15px;height:15px" /> {{ __('Volver a contactos') }}
        </a>

        <div style="display:flex;flex-direction:column;gap:16px;max-width:900px">
            <div class="card card-p fade">
                <div style="display:flex;align-items:center;gap:13px;margin-bottom:16px">
                    <span class="mca-av"><x-ui.icon name="user" /></span>
                    <div style="font-size:18px;font-weight:700;color:var(--ink)">{{ trim($contact->first_name.' '.$contact->last_name) ?: '—' }}</div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
                    @foreach ([
                        [__('Correo'), $contact->email],
                        [__('Teléfono'), $contact->phone ?: '—'],
                        [__('País'), $contact->country ?: '—'],
                        [__('Idioma'), $contact->preferred_language],
                        [__('Consentimiento'), $contact->consent_at ? $contact->consent_at->toDateString().' ('.$contact->consent_source.')' : __('sin registrar')],
                    ] as [$k, $v])
                        <div>
                            <div style="font-size:11.5px;color:var(--muted);font-weight:600">{{ $k }}</div>
                            <div style="font-size:14px;color:var(--ink);margin-top:2px">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 10px">{{ __('Leads') }}</h3>
                @forelse ($contact->leads as $lead)
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:13.5px;padding:9px 0;border-bottom:1px solid var(--line)" wire:key="c-lead-{{ $lead->id }}">
                        <div>{{ optional($lead->program)->name_es ?: $lead->product_type }} — <span class="mca-muted">{{ $lead->status->label() }} · {{ $lead->interest_level->label() }}</span></div>
                        <a href="{{ route('crm.leads.show', $lead) }}" style="color:var(--mca);font-weight:600">{{ __('Abrir') }}</a>
                    </div>
                @empty
                    <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('Sin leads.') }}</p>
                @endforelse
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 10px">{{ __('Intereses por programa') }}</h3>
                @forelse ($contact->programInterests as $interest)
                    <div style="font-size:13.5px;padding:5px 0" wire:key="c-int-{{ $interest->id }}">
                        {{ optional($interest->program)->name_es ?: ('#'.$interest->program_id) }}
                        <span class="mca-muted" style="font-size:12px">({{ $interest->source }} · {{ $interest->created_at?->diffForHumans() }})</span>
                    </div>
                @empty
                    <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('Sin intereses registrados.') }}</p>
                @endforelse
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 10px">{{ __('Conversaciones') }}</h3>
                @forelse ($contact->conversations as $conversation)
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:13.5px;padding:9px 0;border-bottom:1px solid var(--line)" wire:key="c-conv-{{ $conversation->id }}">
                        <div class="mca-muted">{{ $conversation->mode }} · {{ $conversation->language }} · {{ $conversation->last_activity_at?->diffForHumans() }}</div>
                        <a href="{{ route('crm.conversations.show', $conversation) }}" style="color:var(--mca);font-weight:600">{{ __('Ver') }}</a>
                    </div>
                @empty
                    <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('Sin conversaciones.') }}</p>
                @endforelse
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 10px">{{ __('Historial de eventos') }}</h3>
                @forelse ($contact->events as $event)
                    <div style="font-size:12.5px;padding:4px 0;color:var(--muted)" wire:key="c-ev-{{ $event->id }}">
                        <span style="font-family:ui-monospace,monospace;color:var(--ink)">{{ $event->event_type }}</span>
                        · {{ $event->created_at?->diffForHumans() }}
                    </div>
                @empty
                    <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('Sin eventos.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
