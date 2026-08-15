<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Conversacion') }} #{{ $conversation->id }}</h2>
            @if ($conversation->contact)
                <a href="{{ route('crm.contacts.show', $conversation->contact) }}" class="text-sm text-indigo-600 hover:underline">{{ __('Ficha del contacto') }}</a>
            @endif
        </div>

        <div class="text-xs text-gray-500 mb-4">
            {{ __('Modo') }}: {{ $conversation->mode }} · {{ __('Idioma') }}: {{ $conversation->language }} ·
            {{ __('Estado') }}: {{ $conversation->status }} · {{ __('Solo lectura') }}
        </div>

        {{-- Hilo de mensajes --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 mb-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Mensajes') }}</h3>
            @forelse ($conversation->messages as $message)
                <div class="mb-3" wire:key="msg-{{ $message->id }}">
                    <div @class([
                        'inline-block px-3 py-2 rounded-lg text-sm max-w-lg',
                        'bg-indigo-50 text-indigo-900' => $message->sender_type === 'user',
                        'bg-gray-100 text-gray-800' => $message->sender_type !== 'user',
                    ])>
                        <div class="text-xs text-gray-400 mb-1">{{ $message->sender_type }} · {{ $message->created_at?->diffForHumans() }}</div>
                        {{ $message->content }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin mensajes (se llenaran con el widget del Bloque 5).') }}</p>
            @endforelse
        </div>

        {{-- Linea de tiempo de eventos --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('Linea de tiempo de eventos') }}</h3>
            @forelse ($conversation->events as $event)
                <div class="text-xs py-1 text-gray-600" wire:key="conv-ev-{{ $event->id }}">
                    <span class="font-mono">{{ $event->event_type }}</span>
                    <span class="text-gray-400">· {{ $event->created_at?->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Sin eventos.') }}</p>
            @endforelse
        </div>
    </div>
</div>
