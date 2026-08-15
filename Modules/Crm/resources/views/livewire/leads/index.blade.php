<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-6">{{ __('Leads') }}</h2>

        {{-- Filtros --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 mb-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div>
                <label class="text-xs text-gray-500">{{ __('Estado') }}</label>
                <select wire:model.live="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">{{ __('todos') }}</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">{{ __('Area') }}</label>
                <input type="text" wire:model.live.debounce.400ms="area" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
            <div>
                <label class="text-xs text-gray-500">{{ __('Meta') }}</label>
                <input type="text" wire:model.live.debounce.400ms="goal" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
            <div>
                <label class="text-xs text-gray-500">{{ __('Nivel') }}</label>
                <input type="text" wire:model.live.debounce.400ms="level" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
            <div>
                <label class="text-xs text-gray-500">{{ __('Origen') }}</label>
                <input type="text" wire:model.live.debounce.400ms="source" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase">
                        <th class="px-3 py-2">{{ __('Contacto') }}</th>
                        <th class="px-3 py-2">{{ __('Programa') }}</th>
                        <th class="px-3 py-2">{{ __('Area / Meta / Nivel') }}</th>
                        <th class="px-3 py-2">{{ __('Origen') }}</th>
                        <th class="px-3 py-2">{{ __('Interes') }}</th>
                        <th class="px-3 py-2">{{ __('Estado') }}</th>
                        <th class="px-3 py-2">{{ __('Actualizado') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}">
                            <td class="px-3 py-2">
                                {{ optional($lead->contact)->first_name }} {{ optional($lead->contact)->last_name }}
                                <div class="text-xs text-gray-400">{{ optional($lead->contact)->email }}</div>
                            </td>
                            <td class="px-3 py-2">{{ optional($lead->program)->name_es ?: '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $lead->area ?: '—' }} / {{ $lead->goal ?: '—' }} / {{ $lead->level ?: '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $lead->source ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $lead->interest_level->label() }}</td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'text-xs px-2 py-1 rounded',
                                    'bg-blue-100 text-blue-800' => $lead->status->value === 'new',
                                    'bg-yellow-100 text-yellow-800' => in_array($lead->status->value, ['contacted','qualified']),
                                    'bg-green-100 text-green-800' => $lead->status->value === 'enrolled',
                                    'bg-gray-200 text-gray-600' => $lead->status->value === 'discarded',
                                ])>{{ $lead->status->label() }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $lead->updated_at?->diffForHumans() }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('crm.leads.show', $lead) }}" class="text-indigo-600 hover:underline">{{ __('Abrir') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($leads->isEmpty())
                <p class="text-sm text-gray-500 p-4">{{ __('No hay leads con esos filtros.') }}</p>
            @endif
        </div>
    </div>
</div>
