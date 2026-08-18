<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-toolbar">
            <div class="mca-search">
                <x-ui.icon name="search" class="ic" style="width:16px;height:16px" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Buscar por nombre o correo') }}">
            </div>
        </div>

        <div class="card" style="overflow:hidden">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Correo') }}</th>
                            <th>{{ __('País') }}</th>
                            <th>{{ __('Leads') }}</th>
                            <th>{{ __('Conversaciones') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contacts as $contact)
                            <tr class="row" wire:key="contact-{{ $contact->id }}"
                                onclick="window.location='{{ route('crm.contacts.show', $contact) }}'">
                                <td class="t-strong">{{ trim($contact->first_name.' '.$contact->last_name) ?: '—' }}</td>
                                <td class="t-mut">{{ $contact->email }}</td>
                                <td>{{ $contact->country ?: '—' }}</td>
                                <td>{{ $contact->leads_count }}</td>
                                <td>{{ $contact->conversations_count }}</td>
                                <td><a href="{{ route('crm.contacts.show', $contact) }}" style="color:var(--mca);font-weight:600" onclick="event.stopPropagation()">{{ __('Ficha') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="t-empty">{{ __('Sin contactos.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
