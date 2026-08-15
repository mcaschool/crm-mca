<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ $contact->first_name }} {{ $contact->last_name }}</h2>
            <a href="{{ route('crm.contacts.index') }}" class="text-sm text-indigo-600 hover:underline">{{ __('Volver a contactos') }}</a>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6 mb-4">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-gray-400 text-xs">{{ __('Correo') }}</dt><dd>{{ $contact->email }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Telefono') }}</dt><dd>{{ $contact->phone ?: '—' }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Pais') }}</dt><dd>{{ $contact->country ?: '—' }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Idioma') }}</dt><dd>{{ $contact->preferred_language }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Consentimiento') }}</dt><dd>{{ $contact->consent_at ? $contact->consent_at->toDateString().' ('.$contact->consent_source.')' : __('sin registrar') }}</dd></div>
            </dl>
        </div>

        {{-- Leads --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 mb-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Leads') }}</h3>
            @forelse ($contact->leads as $lead)
                <div class="flex items-center justify-between text-sm py-2 border-b border-gray-100" wire:key="c-lead-{{ $lead->id }}">
                    <div>{{ optional($lead->program)->name_es ?: $lead->product_type }} — {{ $lead->status->label() }} ({{ $lead->interest_level->label() }})</div>
                    <a href="{{ route('crm.leads.show', $lead) }}" class="text-indigo-600 hover:underline">{{ __('Abrir') }}</a>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin leads.') }}</p>
            @endforelse
        </div>

        {{-- Intereses por programa --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 mb-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Intereses por programa') }}</h3>
            @forelse ($contact->programInterests as $interest)
                <div class="text-sm py-1" wire:key="c-int-{{ $interest->id }}">
                    {{ optional($interest->program)->name_es ?: ('#'.$interest->program_id) }}
                    <span class="text-xs text-gray-400">({{ $interest->source }} · {{ $interest->created_at?->diffForHumans() }})</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin intereses registrados.') }}</p>
            @endforelse
        </div>

        {{-- Conversaciones --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 mb-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Conversaciones') }}</h3>
            @forelse ($contact->conversations as $conversation)
                <div class="flex items-center justify-between text-sm py-2 border-b border-gray-100" wire:key="c-conv-{{ $conversation->id }}">
                    <div>{{ $conversation->mode }} · {{ $conversation->language }} · {{ $conversation->last_activity_at?->diffForHumans() }}</div>
                    <a href="{{ route('crm.conversations.show', $conversation) }}" class="text-indigo-600 hover:underline">{{ __('Ver') }}</a>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin conversaciones.') }}</p>
            @endforelse
        </div>

        {{-- Eventos (rastro) --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Historial de eventos') }}</h3>
            @forelse ($contact->events as $event)
                <div class="text-xs py-1 text-gray-600" wire:key="c-ev-{{ $event->id }}">
                    <span class="font-mono">{{ $event->event_type }}</span>
                    <span class="text-gray-400">· {{ $event->created_at?->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin eventos.') }}</p>
            @endforelse
        </div>
    </div>
</div>
