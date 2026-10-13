<div class="crm-page mca-page">
    <x-ui.crm-styles />
    <div style="width:100%">
        <x-ui.page-header :title="__('Leads')" :count="$leads->total()" />

        <div class="panel">
            <div class="toolbar">
                <div class="search" style="margin-left:0;flex:1 1 260px;max-width:420px">
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

            @if ($corp === '1')
                <div style="display:flex;align-items:center;gap:8px;padding:10px 4px 2px;font-size:13px;color:var(--muted)">
                    <span class="emp"><x-ui.icon name="building-2" class="i12" /> Interés corporativo</span>
                    Mostrando solo leads con interés corporativo.
                    <a href="{{ route('crm.leads.index') }}" style="color:var(--blue);font-weight:600;text-decoration:none">Quitar filtro</a>
                </div>
            @endif
            @if ($mine === '1' || $period === 'month')
                <div style="display:flex;align-items:center;gap:8px;padding:10px 4px 2px;font-size:13px;color:var(--muted)">
                    <x-ui.icon name="filter" class="i14" />
                    {{ trim(($mine === '1' ? __('Solo tus leads asignados.') : '').' '.($period === 'month' ? __('Solo los actualizados este mes.') : '')) }}
                    <a href="{{ route('crm.leads.index') }}" style="color:var(--blue);font-weight:600;text-decoration:none">{{ __('Quitar filtro') }}</a>
                </div>
            @endif

            <div class="mca-table-scroll">
            <table class="mca-rtable">
                <thead>
                    <tr>
                        <th class="rt-sticky">Nombre</th><th>Correo</th><th>Asesor</th><th>Estado</th><th>Emparejador</th><th>Empresa</th><th>Fecha</th>
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
                            <td class="rt-primary rt-sticky">
                                <div class="who">
                                    <span class="ava">{{ $initials ?: '—' }}</span>
                                    <a class="nm" href="{{ route('crm.leads.show', $lead) }}" style="color:inherit;text-decoration:none">{{ $fullName }}</a>
                                    @if ($lead->contact?->hasUnseenRecontact())
                                        <span class="recontact" title="{{ __('Este contacto volvió a escribir; abre la ficha para verlo.') }}">
                                            <span class="dot"></span> {{ __('Volvió a contactar') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="mail rt-w-md" data-label="Correo">{!! str_replace('@', '@<wbr>', e($lead->contact->email ?? '—')) !!}</td>
                            <td class="rt-w-sm" data-label="Asesor">
                                @php $incompanyLead = $lead->source === 'incompany_web' || $lead->product_type === 'incompany'; @endphp
                                @if ($incompanyLead)
                                    <span class="src"><x-ui.icon name="briefcase" class="i14" /> {{ $lead->capturedByLabel() }}</span>
                                @elseif ($lead->bot)
                                    <span class="src"><x-ui.icon name="bot" class="i14" /> {{ $lead->bot->assistant_name }}</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td data-label="Estado">
                                <span class="chip {{ $lead->status->badgeClass() }}">{{ $lead->status->label() }}</span>
                            </td>
                            <td class="match rt-w-sm" data-label="Emparejador">
                                @if ($lead->goal)
                                    {{ $lead->goal }}@if ($lead->area) <small>· {{ $lead->area }}</small>@endif
                                @elseif ($lead->area)
                                    {{ $lead->area }}
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td data-label="Empresa">
                                @if (isset($corporate[(int) $lead->contact_id]) || $lead->isCorporate())
                                    <span class="emp"><x-ui.icon name="building-2" class="i12" /> Empresa</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td class="date" data-label="Fecha">{{ $lead->created_at?->translatedFormat('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty rt-empty">No hay leads con esos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>

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
