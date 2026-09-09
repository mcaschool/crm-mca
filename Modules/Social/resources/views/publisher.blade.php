<div class="sp-wrap" wire:key="social-publisher">
    <style>
        .sp-wrap{--sp-blue:#1E5AA8;--sp-ink:#1F2A37;--sp-muted:#6B7686;--sp-line:#E6EAF0;--sp-bg:#F4F6F9;
            font-family:'DM Sans',system-ui,sans-serif;color:var(--sp-ink);padding:22px 24px}
        .sp-wrap *{box-sizing:border-box}
        .sp-head h1{margin:0;font-size:20px;font-weight:700;letter-spacing:-.01em}
        .sp-head p{margin:4px 0 18px;font-size:13.5px;color:var(--sp-muted)}
        .sp-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
        @media(max-width:900px){.sp-grid{grid-template-columns:1fr}}
        .sp-card{background:#fff;border:1px solid var(--sp-line);border-radius:14px;padding:18px}
        /* Zona de imagen */
        .sp-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
            min-height:200px;border:1.5px dashed #C7D0DD;border-radius:12px;background:var(--sp-bg);
            cursor:pointer;color:var(--sp-muted);text-align:center;padding:16px;overflow:hidden}
        .sp-drop:hover{border-color:var(--sp-blue);color:var(--sp-blue)}
        .sp-drop__title{font-size:14px;font-weight:600;color:var(--sp-ink)}
        .sp-drop__hint{font-size:12px}
        .sp-preview{max-height:280px;width:100%;object-fit:contain;border-radius:8px}
        .sp-uploading{font-size:12px;color:var(--sp-blue);margin-top:8px}
        .sp-err{color:#B4231F;font-size:12.5px;margin:8px 2px 0}
        .sp-field{display:block;margin-top:16px}
        .sp-field span{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px}
        .sp-field textarea{width:100%;border:1px solid var(--sp-line);border-radius:10px;padding:10px 12px;
            font:inherit;font-size:13.5px;resize:vertical;min-height:96px;color:var(--sp-ink)}
        /* Redes */
        .sp-nets{margin-top:16px}
        .sp-nets__label{display:block;font-size:12.5px;font-weight:600;margin-bottom:8px}
        .sp-net{display:flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid var(--sp-line);
            border-radius:10px;margin-bottom:8px;font-size:13.5px;font-weight:600;cursor:pointer}
        .sp-net input{width:16px;height:16px;accent-color:var(--sp-blue);cursor:pointer}
        .sp-net.off{opacity:.55;cursor:not-allowed}
        .sp-net em{font-style:normal;font-weight:400;color:var(--sp-muted);font-size:12px}
        /* Botón */
        .sp-btn{margin-top:18px;width:100%;border:none;border-radius:10px;height:44px;font-weight:700;
            font-size:14px;background:var(--sp-blue);color:#fff;cursor:pointer;font-family:inherit}
        .sp-btn:hover{background:#17497f}
        .sp-btn:disabled{background:#9db3cf;cursor:progress}
        /* Resultado */
        .sp-result h2{margin:0 0 12px;font-size:15px;font-weight:700}
        .sp-res{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:12px;border:1px solid var(--sp-line);
            border-radius:10px;margin-bottom:10px}
        .sp-res.ok{background:#EEF7EF;border-color:#CDE7D0}
        .sp-res.ko{background:#FCE9E9;border-color:#F1C4C4}
        .sp-res__net{font-weight:700;font-size:14px}
        .sp-badge{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:3px 9px;border-radius:20px}
        .sp-badge.ok{background:#1E824C;color:#fff}
        .sp-badge.ko{background:#B4231F;color:#fff}
        .sp-link{font-size:12.5px;font-weight:600;color:var(--sp-blue);text-decoration:none}
        .sp-link:hover{text-decoration:underline}
        .sp-res__err{flex-basis:100%;margin:2px 0 0;font-size:12px;color:#8A1C1C;line-height:1.4}
        .sp-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;
            text-align:center;color:var(--sp-muted);min-height:220px;padding:20px}
        .sp-empty p{margin:0;font-size:14px;font-weight:600;color:var(--sp-ink)}
        .sp-empty small{font-size:12.5px;max-width:280px}
    </style>

    <div class="sp-head">
        <h1>{{ __('Publicador') }}</h1>
        <p>{{ __('Publica una imagen y su descripción a la vez en la Página de Facebook y en Instagram.') }}</p>
    </div>

    <div class="sp-grid">
        {{-- ---------- FORMULARIO ---------- --}}
        <form class="sp-card" wire:submit.prevent="publish">
            <label class="sp-drop">
                @if ($image)
                    <img src="{{ $image->temporaryUrl() }}" class="sp-preview" alt="{{ __('Vista previa') }}">
                    <span class="sp-drop__hint">{{ __('Haz clic para cambiar la imagen') }}</span>
                @else
                    <x-ui.icon name="image" class="w-8 h-8" />
                    <span class="sp-drop__title">{{ __('Subir imagen') }}</span>
                    <span class="sp-drop__hint">{{ __('JPG o PNG · se normaliza a JPEG para publicar') }}</span>
                @endif
                <input type="file" wire:model="image" accept="image/jpeg,image/png" hidden>
            </label>
            <div wire:loading wire:target="image" class="sp-uploading">{{ __('Subiendo imagen…') }}</div>
            @error('image') <p class="sp-err">{{ $message }}</p> @enderror

            <label class="sp-field">
                <span>{{ __('Descripción') }}</span>
                <textarea wire:model="caption" rows="4" maxlength="2200"
                          placeholder="{{ __('Escribe la descripción de la publicación…') }}"></textarea>
            </label>

            <div class="sp-nets">
                <span class="sp-nets__label">{{ __('Publicar en') }}</span>
                <label class="sp-net {{ $hasFacebook ? '' : 'off' }}">
                    <input type="checkbox" wire:model="toFacebook" @checked($toFacebook && $hasFacebook) @disabled(! $hasFacebook)>
                    @include('social::partials.provider-icon', ['provider' => 'facebook', 'size' => 18])
                    {{ __('Facebook (Página)') }} @unless($hasFacebook) <em>· {{ __('sin canal') }}</em> @endunless
                </label>
                @php($igOn = $hasInstagram && $igPublishEnabled)
                <label class="sp-net {{ $igOn ? '' : 'off' }}">
                    <input type="checkbox" wire:model="toInstagram" @checked($toInstagram && $igOn) @disabled(! $igOn)>
                    @include('social::partials.provider-icon', ['provider' => 'instagram', 'size' => 18])
                    {{ __('Instagram') }}
                    @if (! $hasInstagram)
                        <em>· {{ __('sin canal') }}</em>
                    @elseif (! $igPublishEnabled)
                        <em>· {{ __('No disponible temporalmente (pendiente de aprobación de Meta)') }}</em>
                    @endif
                </label>
            </div>

            <button type="submit" class="sp-btn" wire:target="publish" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="publish">{{ __('Publicar') }}</span>
                <span wire:loading wire:target="publish">{{ __('Publicando…') }}</span>
            </button>
        </form>

        {{-- ---------- RESULTADO POR RED ---------- --}}
        <div class="sp-card sp-result">
            @if ($result)
                <h2>{{ __('Resultado') }}</h2>
                @foreach ($result as $r)
                    <div class="sp-res {{ $r['status'] === 'published' ? 'ok' : 'ko' }}">
                        @include('social::partials.provider-icon', ['provider' => $r['network'] === 'facebook' ? 'facebook' : 'instagram', 'size' => 18])
                        <span class="sp-res__net">{{ $r['network'] === 'facebook' ? 'Facebook' : 'Instagram' }}</span>
                        @if ($r['status'] === 'published')
                            <span class="sp-badge ok"><x-ui.icon name="check" class="w-4 h-4" /> {{ __('Publicado') }}</span>
                            @if ($r['url'])
                                <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="sp-link">{{ __('Ver post') }}</a>
                            @endif
                        @else
                            <span class="sp-badge ko"><x-ui.icon name="x" class="w-4 h-4" /> {{ __('Falló') }}</span>
                        @endif
                        @if ($r['status'] !== 'published' && $r['error'])
                            <p class="sp-res__err">{{ $r['error'] }}</p>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="sp-empty">
                    <x-ui.icon name="image" class="w-8 h-8" />
                    <p>{{ __('Aún no has publicado nada') }}</p>
                    <small>{{ __('Sube una imagen, escribe la descripción y pulsa Publicar. El resultado de cada red aparecerá aquí.') }}</small>
                </div>
            @endif
        </div>
    </div>
</div>
