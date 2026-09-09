@push('styles')
    <style>
        /* La bandeja es una pantalla tipo app: ocupa el ANCHO COMPLETO del bloque central.
           El layout reserva un carril derecho vacío de 300px en todas las páginas; aquí lo
           recogemos SOLO para la bandeja (vía :has) y damos gutters propios al cuerpo. */
        .mca-canvas:has(.social-inbox){padding-right:0}
        .mca-canvas:has(.social-inbox) .mca-rail{display:none}
        .mca-canvas:has(.social-inbox) .mca-canvas-body{padding:18px 22px}
        .social-inbox{--sb-blue:#1E5AA8;--sb-gold:#C9A84C;--sb-ink:#1F2A37;--sb-muted:#6B7686;--sb-line:#E6EAF0;--sb-bg:#F4F6F9;
            display:flex;gap:16px;height:calc(100vh - 96px);min-height:460px;width:100%;font-family:'DM Sans',system-ui,sans-serif;color:var(--sb-ink)}
        .social-inbox *{box-sizing:border-box}
        /* ---- Panel izquierdo (lista) ---- */
        .sb-list{flex:0 0 340px;width:340px;background:#fff;border:1px solid var(--sb-line);border-radius:14px;display:flex;flex-direction:column;overflow:hidden}
        .sb-list__head{padding:16px 18px 12px;border-bottom:1px solid var(--sb-line)}
        .sb-list__head h1{margin:0;font-size:16px;font-weight:700;letter-spacing:-.01em}
        .sb-list__head p{margin:2px 0 0;font-size:12px;color:var(--sb-muted)}
        .sb-scroll{overflow-y:auto;flex:1}
        .sb-item{display:flex;gap:11px;align-items:flex-start;padding:12px 16px;border-bottom:1px solid var(--sb-line);cursor:pointer;text-align:left;width:100%;background:none;border-left:3px solid transparent;font-family:inherit;transition:background .12s}
        .sb-item:hover{background:#F8FAFC}
        .sb-item.on{background:#EEF4FB;border-left-color:var(--sb-blue)}
        .sb-item__avatar{position:relative;flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:var(--sb-bg);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--sb-blue);font-size:15px}
        .sb-item__badge-src{position:absolute;right:-3px;bottom:-3px;width:20px;height:20px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1.5px #fff}
        .sb-item__body{flex:1;min-width:0}
        .sb-item__top{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
        .sb-item__name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sb-item__time{flex:0 0 auto;font-size:11px;color:var(--sb-muted)}
        .sb-item__preview{margin-top:2px;font-size:12.5px;color:var(--sb-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}
        .sb-item__preview span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sb-unread{flex:0 0 auto;min-width:19px;height:19px;padding:0 6px;border-radius:10px;background:var(--sb-blue);color:#fff;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
        /* ---- Panel derecho (hilo) ---- */
        .sb-thread{flex:1;min-width:0;background:#fff;border:1px solid var(--sb-line);border-radius:14px;display:flex;flex-direction:column;overflow:hidden}
        .sb-thread__head{display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--sb-line)}
        .sb-thread__head .sb-item__avatar{width:38px;height:38px}
        .sb-thread__who{min-width:0}
        .sb-thread__who strong{display:block;font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sb-thread__who small{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--sb-muted);margin-top:1px}
        .sb-msgs{flex:1;overflow-y:auto;padding:20px 22px;background:var(--sb-bg);display:flex;flex-direction:column;gap:10px}
        .sb-bubble{max-width:70%;padding:9px 13px;border-radius:14px;font-size:13.5px;line-height:1.45;box-shadow:0 1px 1px rgba(16,24,40,.05);word-wrap:break-word}
        .sb-bubble time{display:block;margin-top:4px;font-size:10.5px;opacity:.65}
        .sb-in{align-self:flex-start;background:#fff;border:1px solid var(--sb-line);border-bottom-left-radius:4px}
        .sb-out{align-self:flex-end;background:var(--sb-blue);color:#fff;border-bottom-right-radius:4px}
        .sb-daysep{align-self:center;font-size:11px;color:var(--sb-muted);background:#fff;border:1px solid var(--sb-line);border-radius:20px;padding:2px 12px;margin:2px 0}
        /* ---- Caja de redacción (deshabilitada) ---- */
        .sb-compose{border-top:1px solid var(--sb-line);padding:12px 16px;background:#fff}
        .sb-compose__row{display:flex;gap:10px;align-items:flex-end}
        .sb-compose textarea{flex:1;resize:none;border:1px solid var(--sb-line);border-radius:10px;padding:9px 12px;font:inherit;font-size:13.5px;background:#F8FAFC;color:var(--sb-muted);min-height:40px;max-height:40px}
        .sb-compose textarea:disabled{cursor:not-allowed}
        .sb-send{flex:0 0 auto;border:none;border-radius:10px;padding:0 18px;height:40px;font-weight:700;font-size:13.5px;background:#C6D0DD;color:#fff;cursor:not-allowed;font-family:inherit}
        .sb-send--on{background:var(--sb-blue);cursor:pointer}
        .sb-send--on:hover{background:#17497f}
        .sb-compose textarea:not(:disabled){background:#fff;color:var(--sb-ink)}
        .sb-compose__note{margin:8px 2px 0;font-size:11.5px;color:var(--sb-muted);display:flex;align-items:center;gap:6px}
        /* Saliente fallido (error genérico o fuera de ventana 24h). */
        .sb-out--failed{background:#FCE9E9;color:#8A1C1C;border:1px solid #F1C4C4}
        .sb-out--failed time{opacity:.8}
        .sb-bubble__warn{margin-top:6px;font-size:11.5px;line-height:1.35;color:#8A1C1C;background:#fff;border:1px solid #F1C4C4;border-radius:8px;padding:6px 9px;font-weight:500}
        /* ---- Estado vacío ---- */
        .sb-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--sb-muted);text-align:center;padding:30px;background:var(--sb-bg)}
        .sb-empty__icon{width:60px;height:60px;border-radius:50%;background:#fff;border:1px solid var(--sb-line);display:flex;align-items:center;justify-content:center;color:#B4BECC}
        .sb-empty p{margin:0;font-size:14px}
        .sb-empty small{font-size:12.5px;max-width:320px}
        .sb-list__empty{padding:40px 22px;text-align:center;color:var(--sb-muted);font-size:13px}
    </style>
@endpush

<div class="social-inbox" wire:key="social-inbox" wire:poll.2s>
    {{-- ===================== PANEL IZQUIERDO ===================== --}}
    <aside class="sb-list">
        <div class="sb-list__head">
            <h1>{{ __('Bandeja social') }}</h1>
            <p>{{ __(':n conversaciones', ['n' => $conversations->count()]) }}</p>
        </div>
        <div class="sb-scroll">
            @forelse ($conversations as $conv)
                @php $initial = mb_strtoupper(mb_substr($conv->contact_name ?: '?', 0, 1)); @endphp
                <button type="button" wire:click="select({{ $conv->id }})"
                        class="sb-item {{ $selected && $selected->id === $conv->id ? 'on' : '' }}"
                        wire:key="conv-{{ $conv->id }}">
                    <span class="sb-item__avatar">
                        @if ($conv->contact_avatar_url)
                            <img src="{{ $conv->contact_avatar_url }}" alt="" referrerpolicy="no-referrer"
                                 style="width:100%;height:100%;border-radius:50%;object-fit:cover">
                        @else
                            {{ $initial }}
                        @endif
                        <span class="sb-item__badge-src" wire:ignore>
                            @include('social::partials.provider-icon', ['provider' => $conv->provider, 'size' => 14])
                        </span>
                    </span>
                    <span class="sb-item__body">
                        <span class="sb-item__top">
                            <span class="sb-item__name">{{ $conv->contact_name ?: __('Contacto sin nombre') }}</span>
                            <span class="sb-item__time">{{ optional($conv->last_message_at)->diffForHumans(null, true) }}</span>
                        </span>
                        <span class="sb-item__preview">
                            <span>{{ $conv->last_message_preview ?: '—' }}</span>
                            @if ($conv->unread_count > 0)
                                <span class="sb-unread">{{ $conv->unread_count }}</span>
                            @endif
                        </span>
                    </span>
                </button>
            @empty
                <div class="sb-list__empty">{{ __('No hay conversaciones todavía.') }}</div>
            @endforelse
        </div>
    </aside>

    {{-- ===================== PANEL DERECHO ===================== --}}
    <section class="sb-thread">
        @if ($selected)
            @php $sInitial = mb_strtoupper(mb_substr($selected->contact_name ?: '?', 0, 1)); @endphp
            <header class="sb-thread__head">
                <span class="sb-item__avatar">
                    @if ($selected->contact_avatar_url)
                        <img src="{{ $selected->contact_avatar_url }}" alt="" referrerpolicy="no-referrer"
                             style="width:100%;height:100%;border-radius:50%;object-fit:cover">
                    @else
                        {{ $sInitial }}
                    @endif
                    <span class="sb-item__badge-src" wire:ignore wire:key="ico-hdr-av-{{ $selected->provider }}">
                        @include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 14])
                    </span>
                </span>
                <span class="sb-thread__who">
                    <strong>{{ $selected->contact_name ?: __('Contacto sin nombre') }}</strong>
                    <small>
                        <span wire:ignore wire:key="ico-hdr-sm-{{ $selected->provider }}" style="display:inline-flex">@include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 14])</span>
                        {{ $selected->channel?->providerLabel() }} · {{ $selected->channel?->display_name }}
                    </small>
                </span>
            </header>

            <div class="sb-msgs" wire:key="msgs-{{ $selected->id }}"
                 x-data="{ atBottom: true }"
                 x-init="
                    const el = $el;
                    const toBottom = () => { el.scrollTop = el.scrollHeight; };
                    $nextTick(toBottom);
                    el.addEventListener('scroll', () => { atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 48; });
                    new MutationObserver(() => { if (atBottom) toBottom(); }).observe(el, { childList: true, subtree: true });
                 ">
                @forelse ($messages as $msg)
                    @php $failed = in_array($msg->status, ['failed', 'failed_window'], true); @endphp
                    <div class="sb-bubble {{ $msg->direction === 'outbound' ? 'sb-out' : 'sb-in' }} {{ $failed ? 'sb-out--failed' : '' }}"
                         wire:key="msg-{{ $msg->id }}">
                        {!! nl2br(e($msg->body ?: '—')) !!}
                        <time>
                            {{ optional($msg->provider_timestamp ?? $msg->created_at)->format('d/m H:i') }}
                            @if ($msg->direction === 'outbound')
                                @switch($msg->status)
                                    @case('pending') · {{ __('Enviando…') }} @break
                                    @case('sent') · {{ __('Enviado') }} @break
                                    @case('failed') · {{ __('Fallido') }} @break
                                    @case('failed_window') · {{ __('Fallido') }} @break
                                @endswitch
                            @endif
                        </time>
                        @if ($msg->status === 'failed_window')
                            <div class="sb-bubble__warn">{{ __('No se puede responder: pasaron más de 24h desde el último mensaje del contacto.') }}</div>
                        @elseif ($msg->status === 'failed')
                            <div class="sb-bubble__warn">{{ __('No se pudo enviar el mensaje. Inténtalo de nuevo.') }}</div>
                        @endif
                    </div>
                @empty
                    <div class="sb-daysep">{{ __('Sin mensajes en esta conversación.') }}</div>
                @endforelse
            </div>

            <div class="sb-compose">
                @if ($canReply)
                    {{-- La caja la gobierna Alpine y va wire:ignore: el poll de 2 s no debe pisar
                         lo que el usuario está escribiendo. El texto se pasa al enviar. --}}
                    <div class="sb-compose__row" x-data="{ draft: '' }"
                         x-on:keydown.enter.prevent="if (draft.trim() !== '') $wire.send(draft).then(ok => { if (ok) draft = '' })">
                        <textarea rows="1" wire:ignore x-model="draft"
                                  placeholder="{{ __('Escribe una respuesta…') }}"></textarea>
                        <button type="button" class="sb-send sb-send--on"
                                x-on:click="if (draft.trim() !== '') $wire.send(draft).then(ok => { if (ok) draft = '' })"
                                wire:target="send" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="send">{{ __('Enviar') }}</span>
                            <span wire:loading wire:target="send">{{ __('Enviando…') }}</span>
                        </button>
                    </div>
                    <p class="sb-compose__note">
                        <span wire:ignore wire:key="ico-note-{{ $selected->provider }}" style="display:inline-flex">@include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 14])</span>
                        {{ __('Se envía directamente al contacto por :canal.', ['canal' => $selected->channel?->providerLabel()]) }}
                    </p>
                @else
                    <div class="sb-compose__row">
                        <textarea rows="1" disabled placeholder="{{ __('Escribe una respuesta…') }}"></textarea>
                        <button type="button" class="sb-send" disabled>{{ __('Enviar') }}</button>
                    </div>
                    <p class="sb-compose__note">
                        <span wire:ignore style="display:inline-flex"><x-ui.icon name="lock" class="w-4 h-4" /></span>
                        {{ __('El envío de WhatsApp se habilita con su canal propio.') }}
                    </p>
                @endif
            </div>
        @else
            <div class="sb-empty">
                <span class="sb-empty__icon" wire:ignore><x-ui.icon name="inbox" class="w-7 h-7" /></span>
                <p>{{ __('Selecciona una conversación') }}</p>
                <small>{{ __('Elige un chat de la lista para ver el hilo completo. El origen (WhatsApp, Instagram o Messenger) se muestra en cada conversación.') }}</small>
            </div>
        @endif
    </section>
</div>
