<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">

                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold">{{ __('Procesos de IA') }}</h2>
                    <a href="{{ route('integrations.index') }}" class="text-sm text-indigo-600 hover:underline">
                        {{ __('Volver a integraciones') }}
                    </a>
                </div>

                @if (session('status'))
                    <div class="mb-4 text-sm text-green-700">{{ session('status') }}</div>
                @endif

                @if ($bots->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('No hay bots configurados todavia.') }}</p>
                @else
                    <div class="mb-6">
                        <x-input-label for="botId" :value="__('Bot')" />
                        <select id="botId" wire:model.live="botId"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($bots as $bot)
                                <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <form wire:submit="save" class="space-y-6">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="py-2">{{ __('Proceso') }}</th>
                                    <th class="py-2">{{ __('Proveedor de IA') }}</th>
                                    <th class="py-2">{{ __('Modelo') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($processes as $process)
                                    <tr wire:key="proc-{{ $process }}">
                                        <td class="py-2 pe-4 font-medium">{{ $process }}</td>
                                        <td class="py-2 pe-4">
                                            <select wire:model="rows.{{ $process }}.integration_id"
                                                    class="block w-full border-gray-300 rounded-md shadow-sm">
                                                <option value="">{{ __('— sin asignar —') }}</option>
                                                @foreach ($aiIntegrations as $ai)
                                                    <option value="{{ $ai->id }}">{{ $ai->name }} ({{ $ai->provider }})</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-2">
                                            <x-text-input type="text" class="block w-full"
                                                wire:model="rows.{{ $process }}.model"
                                                placeholder="p. ej. gpt-5-mini" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                    </form>
                @endif

            </div>
        </div>
    </div>
</div>
