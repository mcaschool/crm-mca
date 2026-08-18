<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-toolbar">
            <div class="sp"></div>
            <a href="{{ route('integrations.ai-processes') }}" class="btn btn-ghost btn-sm">{{ __('Procesos de IA') }}</a>
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="mca-grid">
            @foreach ($cards as $card)
                @php($integration = $card['integration'])
                @php($state = $integration === null ? 'desconectado' : ($integration->status === 'active' ? 'conectado' : 'inactivo'))
                <div class="card card-p fade" style="display:flex;flex-direction:column" wire:key="int-{{ $card['type'] }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                        <h3 style="font-size:15px;font-weight:700;color:var(--ink);margin:0">{{ $card['label'] }}</h3>
                        @if ($state === 'conectado')
                            <span class="badge badge-on">{{ __('conectado') }}</span>
                        @elseif ($state === 'inactivo')
                            <span class="badge" style="background:var(--mca-warn-soft);color:var(--mca-warn)">{{ __('inactivo') }}</span>
                        @else
                            <span class="badge badge-off">{{ __('desconectado') }}</span>
                        @endif
                    </div>

                    <div style="margin-top:8px;font-size:13px;color:var(--muted);flex:1">
                        @if ($integration === null)
                            {{ __('Sin configurar.') }}
                        @else
                            @if ($integration->provider)
                                <div>{{ __('Proveedor') }}: {{ $integration->provider }}</div>
                            @endif
                            <div>
                                {{ __('Última prueba') }}:
                                @if ($integration->last_tested_at)
                                    {{ $integration->last_tested_at->diffForHumans() }}
                                    @if ($integration->last_test_ok === true)
                                        <span style="color:var(--mca-ok)">✓</span>
                                    @elseif ($integration->last_test_ok === false)
                                        <span style="color:#b3261e">✕</span>
                                    @endif
                                @else
                                    {{ __('nunca') }}
                                @endif
                            </div>
                            @if ($integration->last_test_message)
                                <div style="font-size:12px;margin-top:4px;color:var(--ink-3, #8A99B2)">{{ $integration->last_test_message }}</div>
                            @endif
                        @endif
                    </div>

                    <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:8px">
                        <a href="{{ route('integrations.configure', $card['type']) }}" class="btn btn-primary btn-sm">{{ __('Configurar') }}</a>
                        @if ($integration !== null)
                            <button type="button" wire:click="test({{ $integration->id }})" wire:loading.attr="disabled" class="btn btn-ghost btn-sm">{{ __('Probar conexión') }}</button>
                            <button type="button" wire:click="toggle({{ $integration->id }})" class="btn btn-soft btn-sm">
                                {{ $integration->status === 'active' ? __('Desactivar') : __('Activar') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
