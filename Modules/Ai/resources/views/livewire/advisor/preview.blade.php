{{-- «Probar asesor»: página independiente del enlace privado. Mismo asesor real, canal de prueba. --}}
<div class="pv-wrap">
    <style>
        .pv-body{margin:0;background:var(--mca-page-bg);font-family:var(--mca-font);color:var(--mca-ink);-webkit-font-smoothing:antialiased}
        .pv-wrap{max-width:760px;margin:0 auto;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;padding:16px;box-sizing:border-box}
        .pv-head{display:flex;align-items:center;gap:12px;background:var(--mca-card);border:1px solid var(--mca-card-border);border-radius:var(--mca-radius);padding:12px 14px;box-shadow:var(--mca-shadow-sm)}
        .pv-av{width:44px;height:44px;border-radius:50%;overflow:hidden;flex:none;display:grid;place-items:center;background:var(--mca-blue-soft);color:var(--mca-blue);font-weight:700;font-size:18px}
        .pv-av img{width:100%;height:100%;object-fit:cover}
        .pv-id{flex:1;min-width:0}
        .pv-id b{display:block;font-size:16px}
        .pv-id span{font-size:12.5px;color:var(--mca-ink-2)}
        .pv-badge{display:inline-flex;align-items:center;gap:6px;background:var(--mca-warn-soft);color:#8A5A0C;border:1px solid #F1DDB4;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;white-space:nowrap}
        .pv-badge i{width:7px;height:7px;border-radius:50%;background:var(--mca-warn)}
        .pv-tools{display:flex;gap:6px;align-items:center;margin-top:10px;flex-wrap:wrap}
        .pv-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 14px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;color:var(--mca-ink);font:inherit;font-size:13px;font-weight:600;cursor:pointer}
        .pv-btn:hover{border-color:var(--mca-blue);color:var(--mca-blue)}
        .pv-btn.on{background:var(--mca-blue-soft);border-color:var(--mca-blue);color:var(--mca-blue)}
        .pv-btn.primary{background:var(--mca-blue);border-color:var(--mca-blue);color:#fff}
        .pv-btn.primary:hover{background:var(--mca-blue-hover);color:#fff}
        .pv-btn[disabled]{opacity:.6;cursor:default}
        .pv-note{margin-top:10px;font-size:12.5px;color:var(--mca-ink-2);line-height:1.5}
        .pv-log{flex:1;margin-top:14px;display:flex;flex-direction:column;gap:12px;padding-bottom:12px}
        .pv-teaser{align-self:flex-start;max-width:min(420px,90%);background:#fff;border:1px solid #EAEEF4;border-radius:16px 16px 16px 4px;padding:13px 15px;box-shadow:0 10px 26px rgba(19,37,61,.10);font-size:14px;line-height:1.45}
        .pv-launch{align-self:flex-start;display:inline-flex;align-items:center;gap:11px;border:1.5px solid rgba(30,90,168,.32);background:#fff;border-radius:40px;padding:10px 20px 10px 12px;cursor:pointer;font:inherit;color:var(--mca-ink);box-shadow:0 8px 22px rgba(19,37,61,.12)}
        .pv-launch .ic{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:rgba(30,90,168,.12);color:var(--mca-blue)}
        .pv-launch .tx{display:flex;flex-direction:column;text-align:left;line-height:1.2}
        .pv-launch .tx b{font-size:14.5px}
        .pv-launch .tx small{font-size:11px;color:#22935A;font-weight:500}
        .pv-msg{max-width:85%;padding:11px 14px;border-radius:14px;font-size:14px;line-height:1.5;word-wrap:break-word}
        .pv-msg.user{align-self:flex-end;background:var(--mca-blue);color:#fff;border-bottom-right-radius:4px}
        .pv-msg.bot{align-self:flex-start;background:#fff;border:1px solid var(--mca-card-border);border-bottom-left-radius:4px}
        .pv-msg.bot a{color:var(--mca-blue);font-weight:600;word-break:break-all}
        .pv-rate{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px dashed var(--mca-card-border)}
        .pv-rate button{height:28px;padding:0 10px;border-radius:8px;border:1px solid var(--mca-card-border);background:#fff;font:inherit;font-size:12px;font-weight:600;color:var(--mca-ink-2);cursor:pointer}
        .pv-rate button.ok{background:var(--mca-ok-soft);border-color:var(--mca-ok);color:var(--mca-ok)}
        .pv-rate button.bad{background:var(--mca-warn-soft);border-color:var(--mca-warn);color:#8A5A0C}
        .pv-obs{margin-top:8px;display:flex;flex-direction:column;gap:6px}
        .pv-obs textarea{width:100%;box-sizing:border-box;min-height:64px;border:1px solid var(--mca-card-border);border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;resize:vertical}
        .pv-alert{background:var(--mca-warn-soft);border:1px solid #F1DDB4;border-radius:12px;padding:10px 12px;font-size:13px}
        .pv-form{position:sticky;bottom:0;background:var(--mca-page-bg);padding:10px 0 4px;display:flex;gap:8px;align-items:flex-end}
        .pv-form textarea{flex:1;min-height:44px;max-height:140px;border:1px solid var(--mca-card-border);border-radius:12px;padding:11px 12px;font:inherit;font-size:14px;resize:vertical;box-sizing:border-box}
        .pv-form textarea:focus,.pv-obs textarea:focus{outline:none;border-color:var(--mca-blue);box-shadow:0 0 0 3px rgba(30,90,168,.12)}
        .pv-err{color:#B42318;font-size:12.5px}
        .pv-typing{align-self:flex-start;font-size:12.5px;color:var(--mca-ink-2)}
        @media (max-width:560px){ .pv-wrap{padding:10px} .pv-head{flex-wrap:wrap} .pv-msg{max-width:92%} }
    </style>

    <header class="pv-head">
        <span class="pv-av">
            @if ($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="{{ $bot->assistant_name }}">
            @else
                {{ mb_substr((string) $bot->assistant_name, 0, 1) }}
            @endif
        </span>
        <div class="pv-id">
            <b>{{ $bot->assistant_name }}</b>
            <span>{{ __('Asistente virtual institucional') }}</span>
        </div>
        <span class="pv-badge" role="status"><i></i> {{ __('Modo de prueba') }}</span>
    </header>

    <div class="pv-tools">
        <button type="button" wire:click="setLang('es')" @class(['pv-btn', 'on' => $lang === 'es'])>ES</button>
        <button type="button" wire:click="setLang('en')" @class(['pv-btn', 'on' => $lang === 'en'])>EN</button>
        @if ($started)
            <button type="button" wire:click="restart" wire:confirm="{{ __('¿Empezar una conversación de prueba nueva?') }}" class="pv-btn">↺ {{ __('Reiniciar conversación') }}</button>
        @endif
    </div>
    <p class="pv-note">{{ __('Conversación interna de prueba con el asesor real (mismo modelo, instrucciones y conocimiento). No crea contactos ni leads, no envía nada a Instagram, Messenger ni WhatsApp y no cuenta en las métricas.') }}</p>

    <main class="pv-log" aria-live="polite">
        {{-- Presentación del widget: los dos textos configurados del asesor --}}
        <div class="pv-teaser" data-testid="welcome">{{ $texts['welcome'] }}</div>
        @unless ($started)
            <button type="button" wire:click="start" wire:loading.attr="disabled" class="pv-launch" data-testid="launch">
                <span class="ic">💬</span>
                <span class="tx"><b>{{ $texts['button'] }}</b><small>● {{ __('En línea') }}</small></span>
            </button>
        @endunless

        @foreach ($messages as $m)
            @if ($m->sender_type === 'user')
                <div class="pv-msg user" wire:key="m-{{ $m->id }}">{{ $m->content }}</div>
            @else
                @php $r = $ratings[$m->id] ?? null; @endphp
                <div class="pv-msg bot" wire:key="m-{{ $m->id }}">
                    <div>{!! \Modules\Ai\Livewire\Advisor\Preview::formatReply((string) $m->content) !!}</div>
                    <div class="pv-rate" aria-label="{{ __('Valorar respuesta') }}">
                        <button type="button" wire:click="rate({{ $m->id }}, 'correct')" @class(['ok' => $r === 'correct'])>✓ {{ __('Correcta') }}</button>
                        <button type="button" wire:click="rate({{ $m->id }}, 'needs_improvement')" @class(['bad' => $r === 'needs_improvement'])>✎ {{ __('Necesita mejora') }}</button>
                    </div>
                    @if ($noteFor === $m->id)
                        <div class="pv-obs">
                            <textarea wire:model="note" maxlength="1000" placeholder="{{ __('Observación opcional: qué faltó o qué debería haber dicho.') }}"></textarea>
                            @error('note') <span class="pv-err">{{ $message }}</span> @enderror
                            <div><button type="button" wire:click="saveNote" class="pv-btn">{{ __('Guardar observación') }}</button></div>
                        </div>
                    @endif
                </div>
            @endif
        @endforeach

        <div class="pv-typing" wire:loading wire:target="send,start">{{ __(':name está escribiendo…', ['name' => $bot->assistant_name]) }}</div>

        @if ($notice)
            <div class="pv-alert" role="alert">{{ $notice }}</div>
        @endif
    </main>

    @if ($started)
        <form class="pv-form" wire:submit="send">
            <textarea wire:model="draft" maxlength="1000" rows="1" placeholder="{{ __('Escribe tu mensaje…') }}" aria-label="{{ __('Mensaje') }}"
                      @keydown.enter.prevent="if (! $event.shiftKey) { $wire.send() }"></textarea>
            <button type="submit" class="pv-btn primary" wire:loading.attr="disabled" wire:target="send">{{ __('Enviar') }}</button>
        </form>
        @error('draft') <span class="pv-err">{{ $message }}</span> @enderror
    @endif
</div>
