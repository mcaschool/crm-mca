<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Integraciones') }}</h2>
            <a href="{{ route('integrations.ai-processes') }}"
               class="text-sm text-indigo-600 hover:underline">{{ __('Procesos de IA') }}</a>
        </div>

        @if (session('status'))
            <div class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">{{ session('status') }}</div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($cards as $card)
                @php($integration = $card['integration'])
                <div class="bg-white shadow-sm rounded-lg p-5 flex flex-col" wire:key="int-{{ $card['type'] }}">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900">{{ $card['label'] }}</h3>
                        @php($state = $integration === null ? 'desconectado' : ($integration->status === 'active' ? 'conectado' : 'inactivo'))
                        <span @class([
                            'text-xs px-2 py-1 rounded',
                            'bg-green-100 text-green-800' => $state === 'conectado',
                            'bg-gray-200 text-gray-600' => $state === 'desconectado',
                            'bg-yellow-100 text-yellow-800' => $state === 'inactivo',
                        ])>{{ __($state) }}</span>
                    </div>

                    <div class="mt-2 text-sm text-gray-500 grow">
                        @if ($integration === null)
                            {{ __('Sin configurar.') }}
                        @else
                            @if ($integration->provider)
                                <div>{{ __('Proveedor') }}: {{ $integration->provider }}</div>
                            @endif
                            <div>
                                {{ __('Ultima prueba') }}:
                                @if ($integration->last_tested_at)
                                    {{ $integration->last_tested_at->diffForHumans() }}
                                    @if ($integration->last_test_ok === true)
                                        <span class="text-green-600">✓</span>
                                    @elseif ($integration->last_test_ok === false)
                                        <span class="text-red-600">✕</span>
                                    @endif
                                @else
                                    {{ __('nunca') }}
                                @endif
                            </div>
                            @if ($integration->last_test_message)
                                <div class="text-xs mt-1 text-gray-400">{{ $integration->last_test_message }}</div>
                            @endif
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('integrations.configure', $card['type']) }}"
                           class="text-xs px-3 py-1.5 bg-gray-800 text-white rounded hover:bg-gray-700">{{ __('Configurar') }}</a>

                        @if ($integration !== null)
                            <button type="button" wire:click="test({{ $integration->id }})"
                                    wire:loading.attr="disabled"
                                    class="text-xs px-3 py-1.5 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50">
                                {{ __('Probar conexion') }}
                            </button>
                            <button type="button" wire:click="toggle({{ $integration->id }})"
                                    class="text-xs px-3 py-1.5 border border-gray-300 rounded hover:bg-gray-50">
                                {{ $integration->status === 'active' ? __('Desactivar') : __('Activar') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
