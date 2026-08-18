<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        @if ($conversation->contact)
            <a href="{{ route('crm.contacts.show', $conversation->contact) }}" class="btn btn-ghost btn-sm" style="margin-bottom:14px">
                <x-ui.icon name="chevron-left" class="ic" style="width:15px;height:15px" /> {{ __('Ficha del contacto') }}
            </a>
        @endif

        <div style="display:flex;flex-direction:column;gap:16px;max-width:820px">
            <div class="mca-muted" style="font-size:12.5px">
                {{ __('Conversación') }} #{{ $conversation->id }} · {{ __('Modo') }}: {{ $conversation->mode }} · {{ __('Idioma') }}: {{ $conversation->language }} ·
                {{ __('Estado') }}: {{ $conversation->status }} · {{ __('Solo lectura') }}
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 14px">{{ __('Mensajes') }}</h3>
                @forelse ($conversation->messages as $message)
                    @php $isUser = $message->sender_type === 'user'; @endphp
                    <div style="margin-bottom:12px;{{ $isUser ? 'text-align:right' : '' }}" wire:key="msg-{{ $message->id }}">
                        <div style="display:inline-block;max-width:80%;text-align:left;padding:9px 13px;border-radius:13px;font-size:13.5px;line-height:1.5;
                            {{ $isUser
                                ? 'background:#FFFCF0;border:1px solid #F6EECB;color:var(--ink);border-bottom-right-radius:4px'
                                : 'background:var(--mca-blue-soft);color:var(--ink);border-bottom-left-radius:4px' }}">
                            <div style="font-size:10.5px;font-weight:600;color:var(--muted);margin-bottom:3px">{{ $message->sender_type }} · {{ $message->created_at?->diffForHumans() }}</div>
                            {{ $message->content }}
                        </div>
                    </div>
                @empty
                    <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('Sin mensajes.') }}</p>
                @endforelse
            </div>

            <div class="card card-p fade">
                <h3 style="font-size:14.5px;font-weight:600;margin:0 0 10px">{{ __('Línea de tiempo de eventos') }}</h3>
                @forelse ($conversation->events as $event)
                    <div style="font-size:12.5px;padding:4px 0;color:var(--muted)" wire:key="conv-ev-{{ $event->id }}">
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
