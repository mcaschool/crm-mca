<div class="mca-page">
    <x-ui.page-header :title="__('Contactos')" :count="$contacts->total()" :subtitle="__('Personas que han hablado con un asesor o han dejado sus datos.')" />

    <div class="ui-card ui-card--list">
        <div class="ui-toolbar">
            <x-ui.search wire:model.live.debounce.400ms="search" :placeholder="__('Nombre o correo').'…'" :aria-label="__('Buscar por nombre o correo')" />
        </div>

        <div class="mca-table-scroll">
        <table class="ui-table ui-table--meta3">
            <thead>
                <tr>
                    <th class="rt-sticky">{{ __('Contacto') }}</th>
                    <th>{{ __('País') }}</th>
                    <th>{{ __('Leads') }}</th>
                    <th>{{ __('Conversaciones') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contacts as $contact)
                    @php $fullName = trim($contact->first_name.' '.$contact->last_name) ?: '—'; @endphp
                    <tr class="is-link" wire:key="contact-{{ $contact->id }}"
                        onclick="window.location='{{ route('crm.contacts.show', $contact) }}'">
                        <td class="rt-primary rt-sticky">
                            <div class="ui-person">
                                <x-ui.avatar :name="$fullName" :seed="$contact->id" />
                                <div class="ui-person-text">
                                    <a class="ui-person-name" href="{{ route('crm.contacts.show', $contact) }}">{{ $fullName }}</a>
                                    <span class="ui-person-mail">{!! str_replace('@', '@<wbr>', e($contact->email ?: $contact->phone ?: '—')) !!}</span>
                                </div>
                            </div>
                        </td>
                        <td data-label="{{ __('País') }}">
                            @if ($contact->country)
                                {{ $contact->country }}
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td data-label="{{ __('Leads') }}">{{ $contact->leads_count }}</td>
                        <td data-label="{{ __('Conversaciones') }}">{{ $contact->conversations_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="rt-empty">
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="contact" /></div>
                                <b>{{ __('Sin contactos.') }}</b>
                                {{ __('Prueba con otra búsqueda.') }}
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <x-ui.pager :paginator="$contacts" />
    </div>
</div>
