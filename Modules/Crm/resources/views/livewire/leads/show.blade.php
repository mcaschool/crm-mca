<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Lead') }} #{{ $lead->id }}</h2>
            <a href="{{ route('crm.leads.index') }}" class="text-sm text-indigo-600 hover:underline">{{ __('Volver a leads') }}</a>
        </div>

        @if (session('status'))
            <div class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">{{ session('status') }}</div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
            {{-- Datos del contacto --}}
            <div>
                <h3 class="font-semibold text-gray-800">{{ optional($lead->contact)->first_name }} {{ optional($lead->contact)->last_name }}</h3>
                <div class="text-sm text-gray-500">{{ optional($lead->contact)->email }} · {{ optional($lead->contact)->country ?: '—' }}</div>
                @if ($lead->contact)
                    <a href="{{ route('crm.contacts.show', $lead->contact) }}" class="text-xs text-indigo-600 hover:underline">{{ __('Ver ficha del contacto') }}</a>
                @endif
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-gray-400 text-xs">{{ __('Programa') }}</dt><dd>{{ optional($lead->program)->name_es ?: '—' }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Producto') }}</dt><dd>{{ $lead->product_type }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Area / Meta / Nivel') }}</dt><dd>{{ $lead->area ?: '—' }} / {{ $lead->goal ?: '—' }} / {{ $lead->level ?: '—' }}</dd></div>
                <div><dt class="text-gray-400 text-xs">{{ __('Origen') }}</dt><dd>{{ $lead->source ?: '—' }}</dd></div>
            </dl>

            <hr>

            @if ($isTerminal)
                <div class="text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">
                    {{ __('Este lead esta MATRICULADO (estado terminal en el CRM). La entrega al sistema academico es de un modulo futuro.') }}
                </div>
            @else
                {{-- Cambiar estado --}}
                <div class="flex items-end gap-3">
                    <div class="grow">
                        <x-input-label for="status" :value="__('Estado')" />
                        <select id="status" wire:model="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($statuses as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-2" />
                    </div>
                    <x-primary-button wire:click="changeStatus" type="button">{{ __('Actualizar estado') }}</x-primary-button>
                </div>

                {{-- Cambiar interes --}}
                <div class="flex items-end gap-3">
                    <div class="grow">
                        <x-input-label for="interest_level" :value="__('Interes')" />
                        <select id="interest_level" wire:model="interest_level" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($interestLevels as $l)
                                <option value="{{ $l->value }}">{{ $l->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-primary-button wire:click="changeInterest" type="button">{{ __('Actualizar interes') }}</x-primary-button>
                </div>
            @endif

            {{-- Notas --}}
            <div>
                <x-input-label for="notes" :value="__('Notas internas')" />
                <textarea id="notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea>
                <div class="mt-2">
                    <x-primary-button wire:click="saveNotes" type="button">{{ __('Guardar notas') }}</x-primary-button>
                </div>
            </div>
        </div>
    </div>
</div>
