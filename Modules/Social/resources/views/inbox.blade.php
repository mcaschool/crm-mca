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
        /* ---- Media en burbujas (adjuntos WhatsApp servidos por ruta autenticada) ---- */
        .sb-media{display:block;margin-bottom:4px}
        .sb-media img{max-width:260px;max-height:260px;border-radius:10px;display:block}
        .sb-media video{max-width:280px;max-height:300px;border-radius:10px;display:block}
        .sb-media audio{max-width:260px;display:block}
        .sb-doc{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:9px;background:rgba(255,255,255,.85);border:1px solid var(--sb-line);color:var(--sb-ink);font-size:12.5px;font-weight:600;text-decoration:none;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .sb-out .sb-doc{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.35);color:#fff}
        .sb-doc--pending{opacity:.75;font-weight:500;cursor:default}
        /* ---- Adjuntar (solo WhatsApp) ---- */
        .sb-attach{flex:0 0 auto;width:40px;height:40px;border:1px solid var(--sb-line);border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;color:var(--sb-muted);cursor:pointer;transition:color .12s,border-color .12s}
        .sb-attach:hover{color:var(--sb-blue);border-color:var(--sb-blue)}
        .sb-attach input{display:none}
        .sb-attach__chip{display:inline-flex;align-items:center;gap:8px;margin:8px 2px 0;padding:5px 10px;border:1px solid var(--sb-line);border-radius:9px;background:#F8FAFC;font-size:12px;color:var(--sb-ink);max-width:100%}
        .sb-attach__chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .sb-attach__chip button{border:none;background:none;color:var(--sb-muted);cursor:pointer;font-size:14px;line-height:1;padding:0}
        .sb-attach__chip button:hover{color:#8A1C1C}
        .sb-attach__err{margin:7px 2px 0;font-size:12px;color:#8A1C1C}
        /* ---- Ventana de 24h cerrada + selector de plantillas (WhatsApp) ---- */
        .sb-window{display:flex;flex-direction:column;gap:9px;align-items:flex-start;background:#FFF8E6;border:1px solid #EAD9A8;border-radius:10px;padding:11px 14px;font-size:12.5px;color:#7A5B12;line-height:1.45}
        .sb-window strong{font-weight:700}
        .sb-btn-tpl{border:none;border-radius:9px;padding:8px 14px;background:var(--sb-blue);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit}
        .sb-btn-tpl:hover{background:#17497f}
        .sb-offboard{display:flex;gap:8px;align-items:center;background:#FCE9E9;border:1px solid #F1C4C4;color:#8A1C1C;border-radius:10px;padding:9px 13px;font-size:12.5px;font-weight:600;margin:10px 14px 0}
        .sb-tpl-overlay{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:60;display:flex;align-items:center;justify-content:center;padding:24px}
        .sb-tpl-modal{background:#fff;border-radius:14px;width:min(560px,100%);max-height:min(640px,90vh);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 18px 50px rgba(16,24,40,.25)}
        .sb-tpl-modal__head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--sb-line)}
        .sb-tpl-modal__head h2{margin:0;font-size:15px;font-weight:700}
        .sb-tpl-modal__head button{border:none;background:none;font-size:16px;color:var(--sb-muted);cursor:pointer}
        .sb-tpl-modal__body{padding:14px 18px;overflow-y:auto;display:flex;flex-direction:column;gap:10px}
        .sb-tpl-item{display:flex;flex-direction:column;gap:5px;border:1px solid var(--sb-line);border-radius:10px;padding:11px 13px;cursor:pointer;text-align:left;background:#fff;font-family:inherit;transition:border-color .12s}
        .sb-tpl-item:hover{border-color:var(--sb-blue)}
        .sb-tpl-item__top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .sb-tpl-item__top strong{font-size:13px}
        .sb-tag{display:inline-flex;align-items:center;border-radius:7px;padding:2px 7px;font-size:10.5px;font-weight:700;letter-spacing:.02em;border:1px solid var(--sb-line);color:var(--sb-muted);background:#F8FAFC}
        .sb-tag--ok{background:#E8F5EC;border-color:#BFE3C9;color:#1F7A3D}
        .sb-tpl-item__preview{font-size:12.5px;color:var(--sb-ink);background:var(--sb-bg);border-radius:8px;padding:8px 10px;white-space:pre-wrap}
        .sb-tpl-param{display:flex;flex-direction:column;gap:4px}
        .sb-tpl-param label{font-size:12px;font-weight:600;color:var(--sb-muted)}
        .sb-tpl-param input{border:1px solid var(--sb-line);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px}
        .sb-tpl-modal__foot{display:flex;justify-content:flex-end;gap:10px;padding:12px 18px;border-top:1px solid var(--sb-line)}
        .sb-tpl-back{border:1px solid var(--sb-line);background:#fff;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:600;color:var(--sb-muted);cursor:pointer;font-family:inherit}
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

            @if ($waOffboarded)
                <div class="sb-offboard">
                    <x-ui.icon name="alert-triangle" class="w-4 h-4" />
                    {{ __('Este número está desconectado de la API (offboarded). Los envíos quedan bloqueados hasta reconectar el canal.') }}
                </div>
            @endif

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
                    @php
                        $failed = in_array($msg->status, ['failed', 'failed_window'], true);
                        $atts = is_array($msg->attachments) ? $msg->attachments : [];
                        $hasStoredMedia = collect($atts)->contains(fn ($a) => is_array($a) && ! empty($a['storage_path']));
                    @endphp
                    <div class="sb-bubble {{ $msg->direction === 'outbound' ? 'sb-out' : 'sb-in' }} {{ $failed ? 'sb-out--failed' : '' }}"
                         wire:key="msg-{{ $msg->id }}">
                        @foreach ($atts as $ai => $att)
                            @if (is_array($att) && ! empty($att['storage_path']))
                                @php
                                    $mediaUrl = route('social.media.show', ['message' => $msg->id, 'index' => $ai]);
                                    $mediaType = (string) ($att['type'] ?? 'document');
                                @endphp
                                <span class="sb-media" wire:ignore wire:key="att-{{ $msg->id }}-{{ $ai }}">
                                    @if ($mediaType === 'image' || $mediaType === 'sticker')
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener"><img src="{{ $mediaUrl }}" alt="" loading="lazy"></a>
                                    @elseif ($mediaType === 'video')
                                        <video controls preload="metadata" src="{{ $mediaUrl }}"></video>
                                    @elseif ($mediaType === 'audio')
                                        <audio controls preload="none" src="{{ $mediaUrl }}"></audio>
                                    @else
                                        <a class="sb-doc" href="{{ $mediaUrl }}" target="_blank" rel="noopener">
                                            <x-ui.icon name="file-text" class="w-4 h-4" />
                                            {{ $att['filename'] ?? __('Documento') }}
                                        </a>
                                    @endif
                                </span>
                            @elseif (is_array($att) && ! empty($att['provider_media_id']))
                                <span class="sb-doc sb-doc--pending">{{ __('Adjunto no disponible todavía.') }}</span>
                            @endif
                        @endforeach
                        @if ($msg->body !== null && $msg->body !== '')
                            {!! nl2br(e($msg->body)) !!}
                        @elseif (! $hasStoredMedia && $atts === [])
                            —
                        @endif
                        <time>
                            {{ optional($msg->provider_timestamp ?? $msg->created_at)->format('d/m H:i') }}
                            @if ($msg->direction === 'outbound')
                                @switch($msg->status)
                                    @case('pending') · {{ __('Enviando…') }} @break
                                    @case('sent') · {{ __('Enviado') }} @break
                                    @case('delivered') · {{ __('Entregado') }} @break
                                    @case('read') · {{ __('Leído') }} @break
                                    @case('failed') · {{ __('Fallido') }} @break
                                    @case('failed_window') · {{ __('Fallido') }} @break
                                @endswitch
                            @endif
                        </time>
                        @if ($msg->status === 'failed_window')
                            <div class="sb-bubble__warn">
                                @if ($selected->provider === 'whatsapp')
                                    {{ __('Fuera de la ventana de 24 horas: se requiere una plantilla de WhatsApp aprobada para iniciar o reanudar la conversación.') }}
                                @else
                                    {{ __('No se puede responder: pasaron más de 24h desde el último mensaje del contacto.') }}
                                @endif
                            </div>
                        @elseif ($msg->status === 'failed')
                            <div class="sb-bubble__warn">{{ __('No se pudo enviar el mensaje. Inténtalo de nuevo.') }}</div>
                        @endif
                    </div>
                @empty
                    <div class="sb-daysep">{{ __('Sin mensajes en esta conversación.') }}</div>
                @endforelse
            </div>

            <div class="sb-compose">
                @if ($canReply && $selected->provider === 'whatsapp' && ! $waWindowOpen)
                    {{-- Ventana de 24h cerrada: composer libre bloqueado (también en backend);
                         solo se puede contactar con una plantilla aprobada. --}}
                    <div class="sb-window">
                        <span><strong>{{ __('La ventana de atención de 24 horas ha finalizado.') }}</strong>
                            {{ __('Para contactar nuevamente debes utilizar una plantilla aprobada.') }}</span>
                        <button type="button" class="sb-btn-tpl" wire:click="openTemplates">{{ __('Seleccionar plantilla') }}</button>
                    </div>
                    @error('template')
                        <p class="sb-attach__err">{{ $message }}</p>
                    @enderror
                @elseif ($canReply)
                    {{-- La caja la gobierna Alpine y va wire:ignore: el poll de 2 s no debe pisar
                         lo que el usuario está escribiendo. El texto se pasa al enviar (con
                         adjunto de WhatsApp seleccionado, hace de caption). --}}
                    <div class="sb-compose__row" x-data="{ draft: '' }"
                         x-on:keydown.enter.prevent="if (draft.trim() !== '' || $wire.attachment) $wire.send(draft).then(ok => { if (ok) draft = '' })">
                        @if ($selected->provider === 'whatsapp')
                            <label class="sb-attach" title="{{ __('Adjuntar archivo') }}">
                                <x-ui.icon name="upload" class="w-4 h-4" />
                                <input type="file" wire:model="attachment"
                                       accept="image/jpeg,image/png,video/mp4,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                            </label>
                        @endif
                        <textarea rows="1" wire:ignore x-model="draft"
                                  placeholder="{{ __('Escribe una respuesta…') }}"></textarea>
                        <button type="button" class="sb-send sb-send--on"
                                x-on:click="if (draft.trim() !== '' || $wire.attachment) $wire.send(draft).then(ok => { if (ok) draft = '' })"
                                wire:target="send" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="send">{{ __('Enviar') }}</span>
                            <span wire:loading wire:target="send">{{ __('Enviando…') }}</span>
                        </button>
                    </div>
                    @if ($selected->provider === 'whatsapp')
                        <div wire:loading wire:target="attachment" class="sb-attach__chip">{{ __('Subiendo archivo…') }}</div>
                        @if ($attachment !== null)
                            <div class="sb-attach__chip" wire:loading.remove wire:target="attachment">
                                <span>{{ $attachment->getClientOriginalName() }}
                                    · {{ number_format($attachment->getSize() / 1048576, 1, '.', '') }} MB</span>
                                <button type="button" wire:click="removeAttachment" title="{{ __('Quitar adjunto') }}">✕</button>
                            </div>
                        @endif
                        @error('attachment')
                            <p class="sb-attach__err">{{ $message }}</p>
                        @enderror
                    @endif
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

    {{-- ============ Selector de plantillas (WhatsApp, solo APPROVED) ============ --}}
    @if ($showTemplates && $selected && $selected->provider === 'whatsapp')
        <div class="sb-tpl-overlay" wire:click.self="closeTemplates">
            <div class="sb-tpl-modal">
                <div class="sb-tpl-modal__head">
                    <h2>{{ $waChosen ? __('Completar plantilla') : __('Seleccionar plantilla') }}</h2>
                    <button type="button" wire:click="closeTemplates" title="{{ __('Cerrar') }}">✕</button>
                </div>
                <div class="sb-tpl-modal__body">
                    @if (! $waChosen)
                        @forelse ($waTemplates as $tpl)
                            <button type="button" class="sb-tpl-item" wire:click="chooseTemplate({{ $tpl->id }})" wire:key="tpl-{{ $tpl->id }}">
                                <span class="sb-tpl-item__top">
                                    <strong>{{ $tpl->name }}</strong>
                                    <span class="sb-tag sb-tag--ok">{{ __('Aprobada') }}</span>
                                    <span class="sb-tag">{{ $tpl->category }}</span>
                                    <span class="sb-tag">{{ $tpl->language }}</span>
                                </span>
                                <span class="sb-tpl-item__preview">{{ $tpl->component('BODY')['text'] ?? '' }}</span>
                            </button>
                        @empty
                            <p style="margin:6px 2px;font-size:13px;color:var(--sb-muted)">
                                {{ __('No hay plantillas aprobadas disponibles para este canal. Créalas y sincronízalas en «Plantillas de WhatsApp».') }}
                            </p>
                        @endforelse
                    @else
                        @php
                            $tplBody = (string) ($waChosen->component('BODY')['text'] ?? '');
                            $tplPreview = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($templateParams[(int) $m[1]] ?? '')) !== '' ? (string) $templateParams[(int) $m[1]] : $m[0], $tplBody);
                        @endphp
                        <div class="sb-tpl-item__top">
                            <strong>{{ $waChosen->name }}</strong>
                            <span class="sb-tag">{{ $waChosen->category }}</span>
                            <span class="sb-tag">{{ $waChosen->language }}</span>
                        </div>
                        @foreach ($waChosen->positionalVariables() as $n)
                            <div class="sb-tpl-param" wire:key="tpl-param-{{ $n }}">
                                <label>{{ __('Variable') }} {{ chr(123).chr(123).$n.chr(125).chr(125) }}</label>
                                <input type="text" wire:model.live="templateParams.{{ $n }}">
                            </div>
                        @endforeach
                        <div>
                            <label style="font-size:12px;font-weight:600;color:var(--sb-muted)">{{ __('Vista previa') }}</label>
                            <div class="sb-tpl-item__preview" style="margin-top:4px">{{ $tplPreview }}</div>
                        </div>
                        @error('template')
                            <p class="sb-attach__err">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
                @if ($waChosen)
                    <div class="sb-tpl-modal__foot">
                        <button type="button" class="sb-tpl-back" wire:click="openTemplates">{{ __('Volver') }}</button>
                        <button type="button" class="sb-btn-tpl" wire:click="sendTemplate"
                                wire:loading.attr="disabled" wire:target="sendTemplate">
                            <span wire:loading.remove wire:target="sendTemplate">{{ __('Enviar plantilla') }}</span>
                            <span wire:loading wire:target="sendTemplate">{{ __('Enviando…') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
