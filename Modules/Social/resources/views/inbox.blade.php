@php
    /** Avatar: iniciales y tono estable por persona (mismo patrón que el resto del CRM). */
    $avatar = function (?string $name): array {
        $name = trim((string) $name);
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = $parts === [] ? '?' : mb_strtoupper(mb_substr($parts[0], 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        $tones = ['blue', 'teal', 'gold', 'success', 'deep', 'neutral'];

        return [$initials, $tones[crc32($name) % count($tones)]];
    };
    $attention = ['waiting_human' => 'warning', 'error' => 'danger'];
    $unreadTotal = (int) $conversations->sum('unread_count');
@endphp
<div class="ui-inbox-host" wire:key="social-inbox" wire:poll.2s>
    <div class="ui-inbox" x-data="{ info: window.matchMedia('(min-width: 1440px)').matches }" wire:ignore.self :class="{ 'is-info': info }">

        {{-- ===================== Conversaciones ===================== --}}
        <aside class="ui-card ui-inbox-list" aria-label="{{ __('Conversaciones') }}">
            <div class="ui-inbox-list-h">
                <h1>{{ __('Bandeja social') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $conversations->count() }}<span class="ui-sr"> {{ __('conversaciones') }}</span></span></h1>
                <p>
                    @if ($unreadTotal > 0)
                        <b>{{ trans_choice(':n mensaje sin leer|:n mensajes sin leer', $unreadTotal, ['n' => $unreadTotal]) }}</b> ·
                    @endif
                    {{ __(':n conversaciones', ['n' => $conversations->count()]) }}
                </p>
            </div>
            <ul class="ui-inbox-convs" role="list">
                @forelse ($conversations as $conv)
                    @php
                        [$ini, $tone] = $avatar($conv->contact_name);
                        $isOn = $selected && $selected->id === $conv->id;
                        $needs = $attention[$conv->automation_state ?? ''] ?? null;
                    @endphp
                    <li wire:key="conv-{{ $conv->id }}">
                        <button type="button" wire:click="select({{ $conv->id }})" @class(['ui-conv', 'is-active' => $isOn, 'is-unread' => $conv->unread_count > 0])
                            @if ($isOn) aria-current="true" @endif>
                            <span class="ui-conv-av">
                                @if ($conv->contact_avatar_url)
                                    <span class="ui-avatar ui-avatar--lg"><img src="{{ $conv->contact_avatar_url }}" alt="" referrerpolicy="no-referrer"></span>
                                @else
                                    <span class="ui-avatar ui-avatar--lg ui-tone--{{ $tone }}" aria-hidden="true">{{ $ini }}</span>
                                @endif
                                <span class="ui-conv-src" wire:ignore>@include('social::partials.provider-icon', ['provider' => $conv->provider, 'size' => 13])</span>
                            </span>
                            <span class="ui-conv-main">
                                <span class="ui-conv-top">
                                    <span class="ui-conv-name">{{ $conv->contact_name ?: __('Contacto sin nombre') }}</span>
                                    <time class="ui-conv-time" datetime="{{ optional($conv->last_message_at)->toIso8601String() }}">{{ optional($conv->last_message_at)->diffForHumans(null, true) }}</time>
                                </span>
                                <span class="ui-conv-bottom">
                                    <span class="ui-conv-preview">{{ $conv->last_message_preview ?: __('Sin mensajes') }}</span>
                                    @if ($conv->unread_count > 0)
                                        <span class="ui-badge ui-badge--count ui-tone--blue ui-conv-unread">{{ $conv->unread_count }}<span class="ui-sr"> {{ __('sin leer') }}</span></span>
                                    @endif
                                </span>
                                @if ($needs)
                                    <span class="ui-conv-flag ui-tone--{{ $needs }}"><x-ui.icon name="alert-triangle" /> {{ $conv->automationLabel() }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="ui-empty ui-inbox-empty-list">
                        <div class="ui-empty-ic"><x-ui.icon name="inbox" /></div>
                        <b>{{ __('No hay conversaciones todavía.') }}</b>
                        {{ __('Aparecerán aquí los mensajes de WhatsApp, Instagram y Messenger de los canales conectados.') }}
                    </li>
                @endforelse
            </ul>
        </aside>

        {{-- ===================== Conversación ===================== --}}
        <section class="ui-card ui-inbox-thread" @if ($selected) data-selected aria-label="{{ __('Conversación con :name', ['name' => $selected->contact_name ?: __('Contacto sin nombre')]) }}" @endif>
            @if ($selected)
                @php
                    [$sIni, $sTone] = $avatar($selected->contact_name);
                    $autoChannel = $selected->channel;
                    $autoState = $selected->automation_state ?? 'bot';
                    $hasAdvisor = $autoChannel?->advisor_bot_id !== null;
                    $stateTone = ['bot' => 'blue', 'waiting_human' => 'warning', 'human' => 'success', 'paused' => 'neutral', 'error' => 'danger'][$autoState] ?? 'neutral';
                @endphp
                <header class="ui-inbox-head">
                    <x-ui.button variant="ghost" icon="chevron-left" class="ui-inbox-back" wire:click="$set('selectedId', null)" :aria-label="__('Volver a las conversaciones')" />
                    <span class="ui-conv-av">
                        @if ($selected->contact_avatar_url)
                            <span class="ui-avatar ui-avatar--lg"><img src="{{ $selected->contact_avatar_url }}" alt="" referrerpolicy="no-referrer"></span>
                        @else
                            <span class="ui-avatar ui-avatar--lg ui-tone--{{ $sTone }}" aria-hidden="true">{{ $sIni }}</span>
                        @endif
                    </span>
                    <span class="ui-inbox-who">
                        <strong>{{ $selected->contact_name ?: __('Contacto sin nombre') }}</strong>
                        <span class="ui-inbox-via">
                            <span wire:ignore wire:key="ico-hdr-{{ $selected->provider }}" class="ui-inbox-ico">@include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 14])</span>
                            {{ $selected->channel?->providerLabel() }} · {{ $selected->channel?->display_name }}
                        </span>
                    </span>
                    {{-- Asesor inteligente de la cuenta: asesor, estado visible y control humano --}}
                    @if ($hasAdvisor && ($autoChannel->advisor_enabled || $autoState !== 'bot'))
                        <span class="ui-inbox-auto">
                            <span class="ui-inbox-agent" data-testid="automation-agent">
                                <x-ui.icon name="bot" /> {{ $autoChannel->advisorBot?->assistant_name ?? __('Asesor inteligente') }}{{ $autoChannel->advisor_enabled ? '' : ' · '.__('desactivado en esta cuenta') }}
                            </span>
                            <span class="ui-badge ui-tone--{{ $stateTone }}" data-testid="automation-state"><span class="dot" aria-hidden="true"></span>{{ $selected->automationLabel() }}</span>
                            @if ($autoState !== 'human')
                                <x-ui.button size="sm" variant="primary" wire:click="takeOver">{{ __('Tomar conversación') }}</x-ui.button>
                            @endif
                            @if ($autoState === 'bot')
                                <x-ui.button size="sm" wire:click="pauseAutomation">{{ __('Pausar automatización') }}</x-ui.button>
                            @elseif ($autoChannel->advisor_enabled)
                                <x-ui.button size="sm" wire:click="returnToAdvisor" data-testid="reactivate"
                                    wire:confirm="{{ __('¿Reactivar el asesor inteligente en esta conversación? Solo responderá a los mensajes nuevos, no a los anteriores.') }}">{{ __('Reactivar el asesor') }}</x-ui.button>
                            @endif
                        </span>
                    @endif
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-inbox-info-btn" data-inbox-info-toggle x-on:click="info = ! info"
                        :aria-expanded="info ? 'true' : 'false'" aria-controls="inbox-info" title="{{ __('Datos del contacto') }}" aria-label="{{ __('Datos del contacto') }}">
                        <x-ui.icon name="user" />
                    </button>
                </header>
                @error('reactivate') <p class="ui-error ui-inbox-band" data-testid="reactivate-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror

                @if ($hasAdvisor && ($autoState !== 'bot' || $lastOutbound !== null))
                    {{-- Motivo, quién/cuándo, acción recomendada y si el último mensaje lo envió la IA o una persona --}}
                    <div @class(['ui-inbox-band', 'ui-inbox-note', 'is-error' => $autoState === 'error', 'is-attention' => $autoState === 'waiting_human']) data-testid="automation-note">
                        @if ($autoState !== 'bot' && $selected->automationReasonLabel())
                            <span><b>{{ $selected->automationReasonLabel() }}</b></span>
                        @endif
                        @if ($selected->automation_changed_at)
                            <span class="ui-inbox-note-when">{{ $selected->automationChanger?->name ?? __('Sistema') }} · {{ $selected->automation_changed_at->diffForHumans() }}</span>
                        @endif
                        @if ($autoState !== 'bot' && $selected->automationAction())
                            <span>{{ __('Acción recomendada:') }} {{ $selected->automationAction() }}</span>
                        @endif
                        @if ($lastOutbound !== null)
                            <span data-testid="last-sender">{{ __('Último mensaje enviado por') }} {{ $lastOutbound->sender_type === 'bot' ? __('la IA') : __('una persona') }}</span>
                        @endif
                        {{-- Nota INTERNA (nunca la ve el contacto), derivada de la traza: el asesor respondió sin
                             tener el dato en sus fuentes y sigue atendiendo; un segundo seguido lo transfiere. --}}
                        @if ($autoState === 'bot' && $lastOutbound?->sender_type === 'bot' && is_array($lastOutbound->ai_meta) && ($lastOutbound->ai_meta['kind'] ?? null) === 'unresolved' && ! isset($lastOutbound->ai_meta['streak_reset']))
                            <span data-testid="unresolved-note"><b>{{ __('Consulta fuera de sus fuentes') }}</b> · {{ __('el asesor sigue atendiendo; si vuelve a ocurrir, pasará la conversación a una persona.') }}</span>
                        @endif
                    </div>
                @endif

                @if ($waOffboarded)
                    <div class="ui-inbox-band ui-inbox-note is-error" role="alert">
                        <x-ui.icon name="alert-triangle" />
                        <span>{{ __('Este número está desconectado de la API (offboarded). Los envíos quedan bloqueados hasta reconectar el canal.') }}</span>
                    </div>
                @endif

                <div class="ui-inbox-msgs" wire:key="msgs-{{ $selected->id }}" role="log" aria-live="polite" aria-label="{{ __('Mensajes') }}" tabindex="0"
                     x-data="{ atBottom: true }"
                     x-init="
                        const el = $el;
                        const toBottom = () => { el.scrollTop = el.scrollHeight; };
                        $nextTick(toBottom);
                        el.addEventListener('scroll', () => { atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 48; });
                        new MutationObserver(() => { if (atBottom) toBottom(); }).observe(el, { childList: true, subtree: true });
                     ">
                    @php $prevDay = null; $prevDir = null; @endphp
                    @forelse ($messages as $msg)
                        @php
                            $at = $msg->provider_timestamp ?? $msg->created_at;
                            $day = optional($at)->toDateString();
                            $failed = in_array($msg->status, ['failed', 'failed_window', 'delivery_unknown'], true);
                            $atts = is_array($msg->attachments) ? $msg->attachments : [];
                            $hasStoredMedia = collect($atts)->contains(fn ($a) => is_array($a) && ! empty($a['storage_path']));
                            $out = $msg->direction === 'outbound';
                        @endphp
                        @if ($day !== null && $day !== $prevDay)
                            <div class="ui-inbox-day" role="separator">
                                <span>{{ $at->isToday() ? __('Hoy') : ($at->isYesterday() ? __('Ayer') : \Illuminate\Support\Str::ucfirst($at->translatedFormat('l j \d\e F'))) }}</span>
                            </div>
                            @php $prevDir = null; @endphp
                        @endif
                        <div @class(['ui-bubble', 'ui-bubble--out' => $out, 'ui-bubble--in' => ! $out, 'is-failed' => $failed, 'is-follow' => $prevDir === $msg->direction]) wire:key="msg-{{ $msg->id }}">
                            @if ($msg->sender_type === 'bot')
                                <span class="ui-bubble-tag" data-testid="ai-tag"><x-ui.icon name="sparkles" /> {{ __('Asesor inteligente') }} · {{ __('IA') }}</span>
                            @endif
                            @foreach ($atts as $ai => $att)
                                @if (is_array($att) && ! empty($att['storage_path']))
                                    @php
                                        $mediaUrl = route('social.media.show', ['message' => $msg->id, 'index' => $ai]);
                                        $mediaType = (string) ($att['type'] ?? 'document');
                                    @endphp
                                    <span class="ui-bubble-media" wire:ignore wire:key="att-{{ $msg->id }}-{{ $ai }}">
                                        @if ($mediaType === 'image' || $mediaType === 'sticker')
                                            <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" aria-label="{{ __('Abrir imagen') }}"><img src="{{ $mediaUrl }}" alt="{{ $att['filename'] ?? __('Imagen') }}" loading="lazy"></a>
                                        @elseif ($mediaType === 'video')
                                            <video controls preload="metadata" src="{{ $mediaUrl }}"></video>
                                        @elseif ($mediaType === 'audio')
                                            <audio controls preload="none" src="{{ $mediaUrl }}"></audio>
                                        @else
                                            <a class="ui-bubble-doc" href="{{ $mediaUrl }}" target="_blank" rel="noopener">
                                                <x-ui.icon name="file-text" />
                                                <span>{{ $att['filename'] ?? __('Documento') }}</span>
                                                <x-ui.icon name="download" />
                                            </a>
                                        @endif
                                    </span>
                                @elseif (is_array($att) && ! empty($att['provider_media_id']))
                                    <span class="ui-bubble-doc is-pending">{{ __('Adjunto no disponible todavía.') }}</span>
                                @endif
                            @endforeach
                            @if ($msg->body !== null && $msg->body !== '')
                                <div class="ui-bubble-text">{!! nl2br(e($msg->body)) !!}</div>
                            @elseif (! $hasStoredMedia && $atts === [])
                                <div class="ui-bubble-text">—</div>
                            @endif
                            <span class="ui-bubble-meta">
                                <time datetime="{{ optional($at)->toIso8601String() }}">{{ optional($at)->format('d/m H:i') }}</time>
                                @if ($out)
                                    @switch($msg->status)
                                        @case('pending') · {{ __('Enviando…') }} @break
                                        @case('sending') · {{ __('Enviando…') }} @break
                                        @case('delivery_unknown') · <span data-testid="delivery-unknown">{{ __('Entrega sin confirmar: revísala en el canal') }}</span> @break
                                        @case('sent') · {{ __('Enviado') }} @break
                                        @case('delivered') · {{ __('Entregado') }} @break
                                        @case('read') · {{ __('Leído') }} @break
                                        @case('failed') · {{ __('Fallido') }} @break
                                        @case('failed_window') · {{ __('Fallido') }} @break
                                    @endswitch
                                @endif
                            </span>
                            @if ($msg->status === 'failed_window')
                                <div class="ui-bubble-warn" role="note">
                                    <x-ui.icon name="alert-triangle" />
                                    @if ($selected->provider === 'whatsapp')
                                        {{ __('Fuera de la ventana de 24 horas: se requiere una plantilla de WhatsApp aprobada para iniciar o reanudar la conversación.') }}
                                    @else
                                        {{ __('No se puede responder: pasaron más de 24h desde el último mensaje del contacto.') }}
                                    @endif
                                </div>
                            @elseif ($msg->status === 'failed')
                                <div class="ui-bubble-warn" role="note"><x-ui.icon name="alert-triangle" /> {{ __('No se pudo enviar el mensaje. Inténtalo de nuevo.') }}</div>
                            @endif
                        </div>
                        @php $prevDay = $day; $prevDir = $msg->direction; @endphp
                    @empty
                        <div class="ui-empty ui-inbox-empty-thread">
                            <div class="ui-empty-ic"><x-ui.icon name="message-circle" /></div>
                            <b>{{ __('Sin mensajes en esta conversación.') }}</b>
                        </div>
                    @endforelse
                </div>

                {{-- ===================== Respuesta ===================== --}}
                <div class="ui-inbox-compose">
                    @if ($canReply && $selected->provider === 'whatsapp' && ! $waWindowOpen)
                        {{-- Ventana de 24h cerrada: composer libre bloqueado (también en backend);
                             solo se puede contactar con una plantilla aprobada. --}}
                        <div class="ui-callout ui-tone--warning ui-inbox-window">
                            <x-ui.icon name="clock" />
                            <span><b>{{ __('La ventana de atención de 24 horas ha finalizado.') }}</b>
                                {{ __('Para contactar nuevamente debes utilizar una plantilla aprobada.') }}</span>
                            <x-ui.button size="sm" variant="primary" icon="file-text" wire:click="openTemplates">{{ __('Seleccionar plantilla') }}</x-ui.button>
                        </div>
                        @error('template')
                            <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p>
                        @enderror
                    @elseif ($canReply)
                        {{-- La caja la gobierna Alpine y va wire:ignore: el poll de 2 s no debe pisar
                             lo que el usuario está escribiendo. El texto se pasa al enviar (con
                             adjunto de WhatsApp seleccionado, hace de caption). --}}
                        <div class="ui-inbox-compose-row" x-data="{ draft: '' }"
                             x-on:keydown.enter.prevent="if (draft.trim() !== '' || $wire.attachment) $wire.send(draft).then(ok => { if (ok) draft = '' })">
                            @if ($selected->provider === 'whatsapp')
                                <label class="ui-btn ui-btn--secondary ui-btn--icon ui-inbox-attach" title="{{ __('Adjuntar archivo') }}">
                                    <x-ui.icon name="upload" />
                                    <span class="ui-sr">{{ __('Adjuntar archivo') }}</span>
                                    <input type="file" wire:model="attachment"
                                           accept="image/jpeg,image/png,video/mp4,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                                </label>
                            @endif
                            <textarea rows="1" wire:ignore x-model="draft" class="ui-input ui-inbox-text" aria-label="{{ __('Respuesta') }}"
                                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 144) + 'px'"
                                      placeholder="{{ __('Escribe una respuesta…') }}"></textarea>
                            <button type="button" class="ui-btn ui-btn--primary ui-inbox-send" aria-label="{{ __('Enviar') }}"
                                    x-on:click="if (draft.trim() !== '' || $wire.attachment) $wire.send(draft).then(ok => { if (ok) draft = '' })"
                                    wire:target="send" wire:loading.attr="disabled">
                                <span class="ui-btn-label" wire:loading.remove wire:target="send"><x-ui.icon name="send" /> <span class="ui-hide-sm">{{ __('Enviar') }}</span></span>
                                <span wire:loading wire:target="send"><span class="ui-spin"></span> <span class="ui-hide-sm">{{ __('Enviando…') }}</span></span>
                            </button>
                        </div>
                        @if ($selected->provider === 'whatsapp')
                            <div wire:loading wire:target="attachment" class="ui-inbox-chip"><span class="ui-spin"></span> {{ __('Subiendo archivo…') }}</div>
                            @if ($attachment !== null)
                                <div class="ui-inbox-chip" wire:loading.remove wire:target="attachment">
                                    <x-ui.icon name="file-text" />
                                    <span>{{ $attachment->getClientOriginalName() }}
                                        · {{ number_format($attachment->getSize() / 1048576, 1, '.', '') }} MB</span>
                                    <button type="button" wire:click="removeAttachment" title="{{ __('Quitar adjunto') }}" aria-label="{{ __('Quitar adjunto') }}"><x-ui.icon name="x" /></button>
                                </div>
                            @endif
                            @error('attachment')
                                <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p>
                            @enderror
                        @endif
                        @error('draft')
                            <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p>
                        @enderror
                        <p class="ui-inbox-hint">
                            <span wire:ignore wire:key="ico-note-{{ $selected->provider }}" class="ui-inbox-ico">@include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 13])</span>
                            {{ __('Se envía directamente al contacto por :canal.', ['canal' => $selected->channel?->providerLabel()]) }}
                            <span class="ui-inbox-keys">{{ __('Intro para enviar') }}</span>
                        </p>
                    @else
                        <div class="ui-inbox-compose-row">
                            <textarea rows="1" disabled class="ui-input ui-inbox-text" aria-label="{{ __('Respuesta') }}" placeholder="{{ __('Escribe una respuesta…') }}"></textarea>
                            <button type="button" class="ui-btn ui-btn--primary ui-inbox-send" aria-label="{{ __('Enviar') }}" disabled><x-ui.icon name="send" /> <span class="ui-hide-sm">{{ __('Enviar') }}</span></button>
                        </div>
                        <p class="ui-inbox-hint">
                            <span wire:ignore class="ui-inbox-ico"><x-ui.icon name="lock" /></span>
                            {{ __('El envío de WhatsApp se habilita con su canal propio.') }}
                        </p>
                    @endif
                </div>
            @else
                <div class="ui-empty ui-inbox-empty-thread">
                    <div class="ui-empty-ic" wire:ignore><x-ui.icon name="inbox" /></div>
                    <b>{{ __('Selecciona una conversación') }}</b>
                    {{ __('Elige un chat de la lista para ver el hilo completo. El origen (WhatsApp, Instagram o Messenger) se muestra en cada conversación.') }}
                </div>
            @endif
        </section>

        {{-- ===================== Datos del contacto ===================== --}}
        <div class="ui-inbox-scrim" x-on:click="info = false" aria-hidden="true"></div>
        @if ($selected)
            <aside class="ui-card ui-inbox-info" id="inbox-info" aria-label="{{ __('Datos del contacto') }}">
                <div class="ui-inbox-info-h">
                    <h2>{{ __('Datos del contacto') }}</h2>
                    <x-ui.button size="sm" variant="ghost" icon="x" x-on:click="info = false" :aria-label="__('Cerrar datos del contacto')" />
                </div>
                <div class="ui-inbox-info-id">
                    @if ($selected->contact_avatar_url)
                        <span class="ui-avatar ui-avatar--xl"><img src="{{ $selected->contact_avatar_url }}" alt="" referrerpolicy="no-referrer"></span>
                    @else
                        <span class="ui-avatar ui-avatar--xl ui-tone--{{ $sTone }}" aria-hidden="true">{{ $sIni }}</span>
                    @endif
                    <strong>{{ $selected->contact_name ?: __('Contacto sin nombre') }}</strong>
                    <span class="ui-inbox-via"><span wire:ignore wire:key="ico-info-{{ $selected->provider }}" class="ui-inbox-ico">@include('social::partials.provider-icon', ['provider' => $selected->provider, 'size' => 14])</span> {{ $selected->channel?->providerLabel() }}</span>
                </div>
                <dl class="ui-meta ui-meta--spaced ui-inbox-info-dl">
                    <dt>{{ __('Cuenta') }}</dt><dd>{{ $selected->channel?->display_name ?? '—' }}</dd>
                    @if ($selected->contact_external_id)
                        <dt>{{ $selected->provider === 'whatsapp' ? __('Número') : __('Identificador') }}</dt><dd class="ui-break">{{ $selected->contact_external_id }}</dd>
                    @endif
                    @if ($hasAdvisor)
                        <dt>{{ __('Asesor inteligente') }}</dt><dd>{{ $autoChannel->advisorBot?->assistant_name ?? __('Asesor inteligente') }}{{ $autoChannel->advisor_enabled ? '' : ' · '.__('desactivado en esta cuenta') }}</dd>
                        <dt>{{ __('Atención') }}</dt><dd>{{ $selected->automationLabel() }}</dd>
                    @endif
                    <dt>{{ __('Asignada a') }}</dt><dd>{{ $selected->assignedUser?->name ?? __('Sin asignar') }}</dd>
                    <dt>{{ __('Mensajes') }}</dt><dd>{{ $messages->count() }}@if ($selected->unread_count > 0) · {{ trans_choice(':n sin leer|:n sin leer', $selected->unread_count, ['n' => $selected->unread_count]) }}@endif</dd>
                    @if ($selected->provider === 'whatsapp' && $canReply)
                        <dt>{{ __('Ventana de 24 h') }}</dt><dd><x-ui.badge :tone="$waWindowOpen ? 'success' : 'warning'" dot>{{ $waWindowOpen ? __('Abierta') : __('Cerrada') }}</x-ui.badge></dd>
                    @endif
                    <dt>{{ __('Primer contacto') }}</dt><dd>{{ optional($selected->created_at)->format('d/m/Y H:i') }}</dd>
                    <dt>{{ __('Último mensaje') }}</dt><dd>{{ optional($selected->last_message_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </dl>
            </aside>
        @endif
    </div>

    {{-- ============ Selector de plantillas (WhatsApp, solo APPROVED) ============ --}}
    @if ($showTemplates && $selected && $selected->provider === 'whatsapp')
        <div class="ui-modal-bg" wire:click.self="closeTemplates">
            <div class="ui-modal ui-modal--sm" role="dialog" aria-modal="true" aria-labelledby="tpl-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="tpl-title"><x-ui.icon name="file-text" /> {{ $waChosen ? __('Completar plantilla') : __('Seleccionar plantilla') }}</h2>
                    <x-ui.button variant="ghost" icon="x" wire:click="closeTemplates" :aria-label="__('Cerrar')" />
                </div>
                <div class="ui-modal-b">
                    @if (! $waChosen)
                        @forelse ($waTemplates as $tpl)
                            <button type="button" class="ui-tpl-pick" wire:click="chooseTemplate({{ $tpl->id }})" wire:key="tpl-{{ $tpl->id }}">
                                <span class="ui-person-line">
                                    <strong>{{ $tpl->name }}</strong>
                                    <x-ui.badge tone="success">{{ __('Aprobada') }}</x-ui.badge>
                                    <x-ui.badge tone="neutral">{{ $tpl->category }}</x-ui.badge>
                                    <x-ui.badge tone="neutral">{{ $tpl->language }}</x-ui.badge>
                                </span>
                                <span class="ui-tpl-pick-body">{{ $tpl->component('BODY')['text'] ?? '' }}</span>
                            </button>
                        @empty
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="file-text" /></div>
                                {{ __('No hay plantillas aprobadas disponibles para este canal. Créalas y sincronízalas en «Plantillas de WhatsApp».') }}
                            </div>
                        @endforelse
                    @else
                        @php
                            $tplBody = (string) ($waChosen->component('BODY')['text'] ?? '');
                            $tplPreview = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => trim((string) ($templateParams[(int) $m[1]] ?? '')) !== '' ? (string) $templateParams[(int) $m[1]] : $m[0], $tplBody);
                        @endphp
                        <div class="ui-person-line">
                            <strong>{{ $waChosen->name }}</strong>
                            <x-ui.badge tone="neutral">{{ $waChosen->category }}</x-ui.badge>
                            <x-ui.badge tone="neutral">{{ $waChosen->language }}</x-ui.badge>
                        </div>
                        @foreach ($waChosen->positionalVariables() as $n)
                            <x-ui.field :label="__('Variable').' '.chr(123).chr(123).$n.chr(125).chr(125)" :for="'tpl-param-'.$n" wire:key="tpl-param-{{ $n }}">
                                <input id="tpl-param-{{ $n }}" type="text" class="ui-input" wire:model.live="templateParams.{{ $n }}">
                            </x-ui.field>
                        @endforeach
                        <div class="ui-field">
                            <span class="ui-label">{{ __('Vista previa') }}</span>
                            <div class="ui-tpl-pick-body">{{ $tplPreview }}</div>
                        </div>
                        @error('template')
                            <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p>
                        @enderror
                    @endif
                </div>
                @if ($waChosen)
                    <div class="ui-modal-f">
                        <x-ui.button wire:click="openTemplates">{{ __('Volver') }}</x-ui.button>
                        <button type="button" class="ui-btn ui-btn--primary" wire:click="sendTemplate" wire:loading.attr="disabled" wire:target="sendTemplate">
                            <span class="ui-btn-label" wire:loading.remove wire:target="sendTemplate"><x-ui.icon name="send" /> {{ __('Enviar plantilla') }}</span>
                            <span wire:loading wire:target="sendTemplate"><span class="ui-spin"></span> {{ __('Enviando…') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
