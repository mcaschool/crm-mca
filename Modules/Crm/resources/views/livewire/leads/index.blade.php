<div class="mca-page">
    <x-ui.page-header :title="__('Leads')" :count="$leads->total()" :subtitle="__('Prospectos captados por los asesores inteligentes y los formularios de la web.')">
        <x-slot:actions>
            <x-ui.button icon="download" wire:click="export">{{ __('Exportar') }}</x-ui.button>
            @can('create', \Modules\Crm\Models\Lead::class)
                <x-ui.button variant="primary" icon="plus" :href="route('crm.leads.create')">{{ __('Nuevo lead') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-card ui-card--list">
        <div class="ui-toolbar">
            <x-ui.search wire:model.live.debounce.400ms="search" placeholder="Buscar por nombre o correo…" aria-label="Buscar por nombre o correo" />

            <x-ui.select filter :active="$status !== ''" wire:model.live="status" aria-label="Filtrar por estado">
                <option value="">Estado · todos</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select filter :active="$advisor !== ''" wire:model.live="advisor" aria-label="Filtrar por asesor de origen">
                <option value="">Asesor · todos</option>
                @foreach ($advisors as $a)
                    <option value="{{ $a->id }}">{{ $a->assistant_name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select filter :active="$area !== ''" wire:model.live="area" aria-label="Filtrar por área">
                <option value="">Área · todas</option>
                @foreach ($areas as $areaOption)
                    <option value="{{ $areaOption }}">{{ $areaOption }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($corp === '1')
            <div class="ui-filter-note">
                <x-ui.badge tone="gold" icon="building-2">Interés corporativo</x-ui.badge>
                Mostrando solo leads con interés corporativo.
                <a class="ui-link" href="{{ route('crm.leads.index') }}">Quitar filtro</a>
            </div>
        @endif
        @if ($mine === '1' || $period === 'month')
            <div class="ui-filter-note">
                <x-ui.icon name="filter" />
                {{ trim(($mine === '1' ? __('Solo tus leads asignados.') : '').' '.($period === 'month' ? __('Solo los actualizados este mes.') : '')) }}
                <a class="ui-link" href="{{ route('crm.leads.index') }}">{{ __('Quitar filtro') }}</a>
            </div>
        @endif

        <div class="mca-table-scroll">
        <table class="ui-table">
            <thead>
                <tr>
                    <th class="rt-sticky">Lead</th><th>Estado</th><th>Asesor</th><th>Emparejador</th><th>Empresa</th><th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leads as $lead)
                    @php
                        $fullName = trim(($lead->contact->first_name ?? '').' '.($lead->contact->last_name ?? '')) ?: '—';
                        $incompanyLead = $lead->source === 'incompany_web' || $lead->product_type === 'incompany';
                    @endphp
                    <tr class="is-link" wire:key="lead-{{ $lead->id }}"
                        onclick="window.location='{{ route('crm.leads.show', $lead) }}'">
                        <td class="rt-primary rt-sticky">
                            <div class="ui-person">
                                <x-ui.avatar :name="$fullName" :seed="$lead->contact_id" />
                                <div class="ui-person-text">
                                    <div class="ui-person-line">
                                        <a class="ui-person-name" href="{{ route('crm.leads.show', $lead) }}">{{ $fullName }}</a>
                                        @if ($lead->contact?->hasUnseenRecontact())
                                            <x-ui.badge tone="success" dot pulse title="{{ __('Este contacto volvió a escribir; abre la ficha para verlo.') }}">{{ __('Volvió a contactar') }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <span class="ui-person-mail">{!! str_replace('@', '@<wbr>', e($lead->contact->email ?? '—')) !!}</span>
                                </div>
                            </div>
                        </td>
                        <td class="rt-aside" data-label="Estado"><x-ui.lead-status :status="$lead->status" /></td>
                        <td class="rt-shrink" data-label="Asesor">
                            @if ($incompanyLead)
                                <span class="t-src"><x-ui.icon name="briefcase" /> {{ $lead->capturedByLabel() }}</span>
                            @elseif ($lead->bot)
                                <span class="t-src"><x-ui.icon name="bot" /> {{ $lead->bot->assistant_name }}</span>
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td class="rt-w-sm" data-label="Emparejador">
                            @if ($lead->goal)
                                {{ $lead->goal }}@if ($lead->area)<span class="t-sub">{{ $lead->area }}</span>@endif
                            @elseif ($lead->area)
                                {{ $lead->area }}
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td data-label="Empresa">
                            @if (isset($corporate[(int) $lead->contact_id]) || $lead->isCorporate())
                                <x-ui.badge tone="gold" icon="building-2">Empresa</x-ui.badge>
                            @else
                                <span class="t-nil">—</span>
                            @endif
                        </td>
                        <td class="t-2" data-label="Fecha">{{ $lead->created_at?->translatedFormat('d M Y') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="rt-empty">
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="search" /></div>
                                <b>No hay leads con esos filtros.</b>
                                Prueba con otra búsqueda o quita algún filtro.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        <x-ui.pager :paginator="$leads" />
    </div>
</div>
