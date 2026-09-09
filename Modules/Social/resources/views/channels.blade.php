<div>
    <x-ui.styles />
    @php
        $providers = \Modules\Social\Models\SocialChannel::PROVIDERS;
        $extLabels = ['whatsapp' => 'Phone Number ID', 'messenger' => 'Page ID', 'instagram' => 'IG User ID'];
    @endphp

    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div>
                <h1 class="mca-h1">{{ __('Canales sociales') }}</h1>
                <p class="mca-sub">{{ __('Da de alta cada número de WhatsApp, Página de Facebook o cuenta de Instagram. Las credenciales se guardan cifradas; una vez guardado, el token solo se muestra enmascarado y puede reemplazarse.') }}</p>
            </div>
            <div class="sp" style="flex:1"></div>
            @can('create', \Modules\Social\Models\SocialChannel::class)
                <button type="button" wire:click="create" class="btn btn-primary btn-sm">
                    <x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Nuevo canal') }}
                </button>
            @endcan
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="card card-p" style="display:flex;align-items:center;gap:10px;background:var(--mca-warn-soft, #FFF7E6);border-color:#F0DFAE;margin-bottom:18px">
            <x-ui.icon name="lock" style="width:18px;height:18px;color:#8A6D1B" />
            <span style="font-size:13px;color:#6B5411">{{ __('Usa el TOKEN PERMANENTE (System User), no el temporal: los tokens temporales caducan y romperían el canal.') }}</span>
        </div>

        {{-- ---------------- FORMULARIO (alta / edición) ---------------- --}}
        @if ($showForm)
            <div class="card card-p fade" style="margin-bottom:22px">
                <h3 style="margin:0 0 14px;font-size:15px;font-weight:700">{{ $editingId ? __('Editar canal') : __('Nuevo canal') }}</h3>
                <form wire:submit="save">
                    <div class="field">
                        <label>{{ __('Proveedor') }}</label>
                        <select wire:model.live="provider" @disabled($editingId)>
                            @foreach ($providers as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($editingId) <p class="mca-help">{{ __('El proveedor no se cambia al editar.') }}</p> @endif
                        @error('provider') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <label>{{ __('Nombre para distinguirlo') }}</label>
                        <input type="text" wire:model="display_name" placeholder="{{ __('Ej. Admisiones · Línea 1') }}">
                        @error('display_name') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <label>{{ $extLabels[$provider] ?? __('Identificador externo') }}</label>
                        <input type="text" wire:model="external_id" autocomplete="off">
                        @error('external_id') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <label>{{ __('Token de acceso') }}</label>
                        @if ($currentTokenMask)
                            <p class="mca-help" style="margin-top:0;margin-bottom:6px">
                                {{ __('Actual') }}: <span style="font-family:ui-monospace,monospace">{{ $currentTokenMask }}</span>
                                — {{ __('deja el campo vacío para conservarlo, o escribe uno nuevo para reemplazarlo.') }}
                            </p>
                        @endif
                        <input type="password" wire:model="token" autocomplete="off"
                               placeholder="{{ $editingId ? __('Reemplazar token') : __('Pega el token permanente') }}">
                        @error('token') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" wire:model="is_active" style="width:16px;height:16px"> {{ __('Canal activo') }}
                        </label>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;margin-top:4px">
                        <button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button>
                        <button type="button" wire:click="cancel" class="btn btn-ghost">{{ __('Cancelar') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ---------------- LISTA (tarjetas lado a lado, igual que los webhooks) ---------------- --}}
        <div class="mca-grid">
            @forelse ($grouped as $providerKey => $list)
                @foreach ($list as $c)
                    <div class="card card-p" wire:key="ch-{{ $c->id }}" style="display:flex;flex-direction:column">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                            @include('social::partials.provider-icon', ['provider' => $providerKey, 'size' => 16])
                            <span style="font-size:12px;font-weight:600;color:var(--muted)">{{ $providers[$providerKey] ?? $providerKey }}</span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                            <h3 style="font-size:14.5px;font-weight:700;margin:0">{{ $c->display_name }}</h3>
                            <span class="badge {{ $c->is_active ? 'badge-on' : 'badge-off' }}">{{ $c->is_active ? __('activo') : __('inactivo') }}</span>
                        </div>
                        <div style="margin-top:8px;font-size:13px;color:var(--muted);flex:1">
                            <div>{{ $extLabels[$providerKey] ?? __('ID') }}: <span style="font-family:ui-monospace,monospace">{{ $c->external_id ?: '—' }}</span></div>
                            <div style="margin-top:2px">{{ __('Token') }}: <span style="font-family:ui-monospace,monospace">{{ $masks[$c->id] }}</span></div>
                        </div>
                        <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px">
                            <button type="button" wire:click="edit({{ $c->id }})" class="btn btn-primary btn-sm">{{ __('Editar') }}</button>
                            <button type="button" wire:click="toggle({{ $c->id }})" class="btn btn-soft btn-sm">
                                {{ $c->is_active ? __('Desactivar') : __('Activar') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            @empty
                <p style="color:var(--muted);font-size:13.5px;margin:14px 2px">{{ __('Aún no hay canales configurados. Crea el primero con «Nuevo canal».') }}</p>
            @endforelse
        </div>

        {{-- ---------------- AYUDA DE WEBHOOK ---------------- --}}
        <div style="margin:28px 0 10px">
            <h2 style="font-size:14px;font-weight:700;margin:0">{{ __('Webhook — pégalo en Meta') }}</h2>
            <p class="mca-sub" style="margin-top:2px">{{ __('Callback URL y Verify Token que se configuran en el panel de Meta al suscribir cada webhook. Solo lectura.') }}</p>
        </div>
        <div class="mca-grid">
            @foreach ($webhooks as $wh)
                <div class="card card-p" wire:key="wh-{{ $wh['provider'] }}">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        @include('social::partials.provider-icon', ['provider' => $wh['provider'], 'size' => 18])
                        <h3 style="font-size:14px;font-weight:700;margin:0">{{ $wh['label'] }}</h3>
                    </div>

                    <label style="font-size:11.5px;font-weight:600;color:var(--muted)">{{ __('Callback URL') }}</label>
                    <div style="display:flex;align-items:center;gap:6px;margin:3px 0 10px">
                        <code style="flex:1;min-width:0;overflow:auto;white-space:nowrap;font-size:12px;background:var(--mca-page-bg,#F4F6F9);padding:6px 8px;border-radius:8px">{{ $wh['callback'] }}</code>
                        <button type="button" class="btn btn-soft btn-sm" x-data="{c:false}"
                                @click="navigator.clipboard.writeText($el.dataset.copy); c=true; setTimeout(()=>c=false,1200)"
                                data-copy="{{ $wh['callback'] }}"
                                x-text="c ? '{{ __('¡Copiado!') }}' : '{{ __('Copiar') }}'">{{ __('Copiar') }}</button>
                    </div>

                    <label style="font-size:11.5px;font-weight:600;color:var(--muted)">{{ __('Verify Token') }}</label>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                        <code style="flex:1;min-width:0;overflow:auto;white-space:nowrap;font-size:12px;background:var(--mca-page-bg,#F4F6F9);padding:6px 8px;border-radius:8px">{{ $wh['verify'] ?: __('(define SOCIAL_WEBHOOK_VERIFY_TOKEN)') }}</code>
                        @if ($wh['verify'])
                            <button type="button" class="btn btn-soft btn-sm" x-data="{c:false}"
                                    @click="navigator.clipboard.writeText($el.dataset.copy); c=true; setTimeout(()=>c=false,1200)"
                                    data-copy="{{ $wh['verify'] }}"
                                    x-text="c ? '{{ __('¡Copiado!') }}' : '{{ __('Copiar') }}'">{{ __('Copiar') }}</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
