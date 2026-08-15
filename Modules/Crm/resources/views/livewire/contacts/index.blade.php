<div class="py-12">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Contactos') }}</h2>
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('Buscar por nombre o correo') }}"
                   class="border-gray-300 rounded-md shadow-sm text-sm w-72">
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase">
                        <th class="px-3 py-2">{{ __('Nombre') }}</th>
                        <th class="px-3 py-2">{{ __('Correo') }}</th>
                        <th class="px-3 py-2">{{ __('Pais') }}</th>
                        <th class="px-3 py-2">{{ __('Leads') }}</th>
                        <th class="px-3 py-2">{{ __('Conversaciones') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($contacts as $contact)
                        <tr wire:key="contact-{{ $contact->id }}">
                            <td class="px-3 py-2">{{ $contact->first_name }} {{ $contact->last_name }}</td>
                            <td class="px-3 py-2">{{ $contact->email }}</td>
                            <td class="px-3 py-2">{{ $contact->country ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $contact->leads_count }}</td>
                            <td class="px-3 py-2">{{ $contact->conversations_count }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('crm.contacts.show', $contact) }}" class="text-indigo-600 hover:underline">{{ __('Ficha') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($contacts->isEmpty())
                <p class="text-sm text-gray-500 p-4">{{ __('Sin contactos.') }}</p>
            @endif
        </div>
    </div>
</div>
