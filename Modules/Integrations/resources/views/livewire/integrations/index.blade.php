<div class="mca-page">
    <x-ui.page-header :title="__('Integraciones')" :subtitle="__('Servicios externos conectados al CRM. Las credenciales se guardan cifradas.')">
        <x-slot:actions>
            <x-ui.button icon="video" :href="route('integrations.video')">{{ __('Videoconferencias') }}</x-ui.button>
            <x-ui.button icon="cpu" :href="route('integrations.ai-processes')">{{ __('Procesos de IA') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    <div class="ui-grid">
        @foreach ($cards as $card)
            @php
                $integration = $card['integration'];
                $state = $integration === null ? 'desconectado' : ($integration->status === 'active' ? 'conectado' : 'inactivo');
            @endphp
            <article class="ui-card ui-entity" wire:key="int-{{ $card['type'] }}">
                <div class="ui-entity-h">
                    <div class="ui-person-text">
                        <h2 class="ui-entity-title">{{ __($card['label']) }}</h2>
                    </div>
                    <x-ui.badge :tone="['conectado' => 'success', 'inactivo' => 'warning', 'desconectado' => 'neutral'][$state]" dot>{{ Str::ucfirst(__($state)) }}</x-ui.badge>
                </div>

                <div class="ui-entity-b">
                    @if ($integration === null)
                        <span class="t-nil">{{ __('Sin configurar.') }}</span>
                    @else
                        <dl class="ui-meta">
                            @if ($integration->provider)
                                <dt>{{ __('Proveedor') }}</dt><dd>{{ $integration->provider }}</dd>
                            @endif
                            <dt>{{ __('Última prueba') }}</dt>
                            <dd>
                                @if ($integration->last_tested_at)
                                    {{ $integration->last_tested_at->diffForHumans() }}
                                    @if ($integration->last_test_ok === true)
                                        <x-ui.badge tone="success" icon="check">{{ __('Correcta') }}</x-ui.badge>
                                    @elseif ($integration->last_test_ok === false)
                                        <x-ui.badge tone="danger" icon="x">{{ __('Fallida') }}</x-ui.badge>
                                    @endif
                                @else
                                    <span class="t-nil">{{ __('nunca') }}</span>
                                @endif
                            </dd>
                        </dl>
                        @if ($integration->last_test_message)
                            <p class="ui-note">{{ $integration->last_test_message }}</p>
                        @endif
                    @endif
                </div>

                <div class="ui-card-f">
                    <x-ui.button size="sm" icon="pencil" :href="route('integrations.configure', $card['type'])">{{ __('Configurar') }}</x-ui.button>
                    @if ($integration !== null)
                        <x-ui.button size="sm" icon="refresh" wire:click="test({{ $integration->id }})" wire:loading.attr="disabled">{{ __('Probar conexión') }}</x-ui.button>
                        @php($toggleLabel = $integration->status === 'active' ? __('Desactivar') : __('Activar'))
                        <x-ui.button size="sm" icon="power" wire:click="toggle({{ $integration->id }})" :title="$toggleLabel" :aria-label="$toggleLabel.' '.__($card['label'])"
                            wire:confirm="{{ $integration->status === 'active' ? __('¿Desactivar la integración «:name»? Los procesos que la usan (por ejemplo, las respuestas de IA de los asesores) dejarán de funcionar hasta que vuelva a activarse.', ['name' => __($card['label'])]) : __('¿Activar la integración «:name»? Los procesos configurados volverán a usarla.', ['name' => __($card['label'])]) }}" />
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</div>
