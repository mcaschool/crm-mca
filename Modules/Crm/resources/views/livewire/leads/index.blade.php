<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    <div style="width:100%">

        <div class="panel">
            <div class="toolbar">
                <h2>
                    <x-ui.icon name="users" class="i18" /> Prospectos
                    <span class="count">{{ $leads->total() }}</span>
                </h2>

                <div class="search">
                    <x-ui.icon name="search" class="i16" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar por nombre o correo…" aria-label="Buscar por nombre o correo">
                </div>

                <select class="fbtn" wire:model.live="status" aria-label="Filtrar por estado">
                    <option value="">Estado · todos</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>

                <select class="fbtn" wire:model.live="advisor" aria-label="Filtrar por asesor de origen">
                    <option value="">Asesor · todos</option>
                    @foreach ($advisors as $a)
                        <option value="{{ $a->id }}">{{ $a->assistant_name }}</option>
                    @endforeach
                </select>

                <select class="fbtn" wire:model.live="area" aria-label="Filtrar por área">
                    <option value="">Área · todas</option>
                    @foreach ($areas as $areaOption)
                        <option value="{{ $areaOption }}">{{ $areaOption }}</option>
                    @endforeach
                </select>

                <button type="button" class="fbtn" wire:click="export">
                    <x-ui.icon name="download" class="i15" /> Exportar
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Nombre</th><th>Correo</th><th>Asesor</th><th>Estado</th><th>Emparejador</th><th>Empresa</th><th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        @php
                            $first = $lead->contact->first_name ?? '';
                            $last = $lead->contact->last_name ?? '';
                            $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
                            $fullName = trim($first.' '.$last) ?: '—';
                        @endphp
                        <tr class="row" wire:key="lead-{{ $lead->id }}"
                            onclick="window.location='{{ route('crm.leads.show', $lead) }}'">
                            <td>
                                <div class="who">
                                    <span class="ava">{{ $initials ?: '—' }}</span>
                                    <span class="nm">{{ $fullName }}</span>
                                    @if ($lead->contact?->hasUnseenRecontact())
                                        <span class="recontact" title="{{ __('Este contacto volvió a escribir; abre la ficha para verlo.') }}">
                                            <span class="dot"></span> {{ __('Volvió a contactar') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="mail">{{ $lead->contact->email ?? '—' }}</td>
                            <td>
                                @if ($lead->bot)
                                    <span class="src"><x-ui.icon name="bot" class="i14" /> {{ $lead->bot->assistant_name }}</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="chip {{ $lead->status->badgeClass() }}">{{ $lead->status->label() }}</span>
                            </td>
                            <td class="match">
                                @if ($lead->goal || $lead->area)
                                    {{ $lead->goal ?: '—' }}@if ($lead->area) <small>· {{ $lead->area }}</small>@endif
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td>
                                @if (isset($corporate[(int) $lead->contact_id]))
                                    <span class="emp"><x-ui.icon name="building-2" class="i12" /> Empresa</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td class="date">{{ $lead->created_at?->translatedFormat('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty">No hay leads con esos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($leads->hasPages())
                <div class="pager">
                    <span>Mostrando {{ $leads->firstItem() }}–{{ $leads->lastItem() }} de {{ $leads->total() }}</span>
                    <div class="links">
                        @if ($leads->onFirstPage())
                            <span class="pg dis"><x-ui.icon name="chevron-left" class="i14" /></span>
                        @else
                            <a href="#" wire:click.prevent="previousPage" rel="prev"><x-ui.icon name="chevron-left" class="i14" /></a>
                        @endif
                        <span class="pg cur">{{ $leads->currentPage() }} / {{ $leads->lastPage() }}</span>
                        @if ($leads->hasMorePages())
                            <a href="#" wire:click.prevent="nextPage" rel="next"><x-ui.icon name="chevron-right" class="i14" /></a>
                        @else
                            <span class="pg dis"><x-ui.icon name="chevron-right" class="i14" /></span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
