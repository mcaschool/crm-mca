{{-- Conversación del widget (solo lectura): mismo hilo (.ui-chat) y línea de tiempo (.ui-timeline)
     que la ficha del lead. El prospecto a la derecha; el asesor y el sistema a la izquierda. --}}
<div class="mca-page">
    @php
        $contactFirst = $conversation->contact?->first_name ?: '';
    @endphp
    <div class="ui-narrow">
        <x-ui.page-header :title="__('Conversación').' #'.$conversation->id"
            :back="$conversation->contact ? route('crm.contacts.show', $conversation->contact) : null"
            :back-label="__('Ficha del contacto')">
            <x-slot:badge><x-ui.badge tone="neutral" icon="eye">{{ __('Solo lectura') }}</x-ui.badge></x-slot:badge>
        </x-ui.page-header>

        <div class="ui-stack">
            <div class="ui-record-meta">
                <span>{{ __('Modo') }}: <b>{{ ['guided' => __('Guiado'), 'celia' => 'Celia'][$conversation->mode] ?? $conversation->mode }}</b></span>
                <span>{{ __('Idioma') }}: <b>{{ ['es' => __('Español'), 'en' => 'English'][$conversation->language] ?? $conversation->language }}</b></span>
                <span>{{ __('Estado') }}: <b>{{ ['open' => __('Abierta'), 'closed' => __('Cerrada'), 'abandoned' => __('Abandonada')][$conversation->status] ?? $conversation->status }}</b></span>
            </div>

            <section class="ui-card" aria-labelledby="h-conv-msgs">
                <div class="ui-card-h">
                    <x-ui.icon name="message-circle" class="ui-card-ic" /><h2 id="h-conv-msgs">{{ __('Mensajes') }}</h2>
                    @if ($conversation->messages->isNotEmpty())<span class="ui-badge ui-badge--count ui-tone--neutral">{{ $conversation->messages->count() }}</span>@endif
                </div>
                @if ($conversation->messages->isNotEmpty())
                    <div class="ui-chat">
                        @foreach ($conversation->messages as $message)
                            @php
                                $isUser = $message->sender_type === 'user';
                                $from = match ($message->sender_type) {
                                    'user' => $contactFirst ?: __('Prospecto'),
                                    'celia' => 'Celia',
                                    'system' => __('Sistema'),
                                    default => $message->sender_type,
                                };
                                $html = e($message->content);
                                $html = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html);
                            @endphp
                            <div class="ui-msg {{ $isUser ? 'ui-msg--in' : '' }}" wire:key="msg-{{ $message->id }}">
                                <div class="ui-msg-from">{{ $from }} · <time datetime="{{ $message->created_at?->toIso8601String() }}" title="{{ $message->created_at?->translatedFormat('d M Y · H:i') }}">{{ $message->created_at?->diffForHumans() }}</time></div>
                                <div class="ui-msg-bubble">{!! nl2br($html) !!}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="ui-card-p">
                        <div class="ui-callout"><x-ui.icon name="message-circle" /><span>{{ __('Sin mensajes.') }}</span></div>
                    </div>
                @endif
            </section>

            <section class="ui-card" aria-labelledby="h-conv-events">
                <div class="ui-card-h">
                    <x-ui.icon name="clock" class="ui-card-ic" /><h2 id="h-conv-events">{{ __('Línea de tiempo de eventos') }}</h2>
                    @if ($conversation->events->isNotEmpty())<span class="ui-badge ui-badge--count ui-tone--neutral">{{ $conversation->events->count() }}</span>@endif
                </div>
                @if ($conversation->events->isNotEmpty())
                    <ol class="ui-timeline">
                        @foreach ($conversation->events as $event)
                            @php $detail = $event->detail(); @endphp
                            <li wire:key="conv-ev-{{ $event->id }}">
                                <span class="ui-tl-ic ui-tone--neutral"><x-ui.icon name="{{ $event->icon() }}" /></span>
                                <div class="ui-tl-body">
                                    {{ $event->label() }}@if ($detail)<span class="ui-tl-detail">: {{ $detail }}</span>@endif
                                    <div class="ui-tl-time"><time datetime="{{ $event->created_at?->toIso8601String() }}" title="{{ $event->created_at?->translatedFormat('d M Y · H:i') }}">{{ $event->created_at?->diffForHumans() }}</time></div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="ui-list-empty">{{ __('Sin eventos.') }}</p>
                @endif
            </section>
        </div>
    </div>
</div>
