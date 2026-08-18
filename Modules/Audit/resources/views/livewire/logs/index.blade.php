@use('Modules\Audit\Support\AuditPresenter')
<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @once
        <style>
            .crm-page .chip.ev-ok{background:#E5F4EE;color:#1F8A5B}
            .crm-page .chip.ev-info{background:#E8F1FD;color:#1E5AA8}
            .crm-page .chip.ev-warn{background:#FBF0DC;color:#B4791A}
            .crm-page .chip.ev-danger{background:#FCECEC;color:#C0392B}
            .crm-page .chip.ev-neutral{background:#EEF1F6;color:#5A6B84}
            .crm-page td.actor .nm{font-weight:600;color:var(--ink)}
            .crm-page td.actor .sub{font-size:11.5px;color:var(--muted)}
            .crm-page td.ip{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:var(--muted)}
            .crm-page td.ent{color:var(--ink-2,#5A6B84)}
            .crm-page .fbtn.ghost{color:var(--muted)}
        </style>
    @endonce

    <div style="width:100%">
        <div class="panel">
            <div class="toolbar">
                <h2>
                    <x-ui.icon name="shield" class="i18" /> Auditoría de seguridad
                    <span class="count">{{ $logs->total() }}</span>
                </h2>

                <select class="fbtn" wire:model.live="action" aria-label="Filtrar por tipo de acción">
                    <option value="">Acción · todas</option>
                    @foreach ($actionOptions as $opt)
                        <option value="{{ $opt }}">{{ AuditPresenter::actionLabel($opt) }}</option>
                    @endforeach
                </select>

                <select class="fbtn" wire:model.live="actor" aria-label="Filtrar por actor">
                    <option value="">Actor · todos</option>
                    @foreach ($actorOptions as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>

                <input type="date" class="fbtn" wire:model.live="from" aria-label="Desde la fecha" max="{{ now()->format('Y-m-d') }}">
                <input type="date" class="fbtn" wire:model.live="to" aria-label="Hasta la fecha" max="{{ now()->format('Y-m-d') }}">

                @if ($action !== '' || $actor !== '' || $from !== '' || $to !== '')
                    <button type="button" class="fbtn ghost" wire:click="clearFilters">
                        <x-ui.icon name="x" class="i15" /> Limpiar
                    </button>
                @endif
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Fecha / hora</th><th>Actor</th><th>Acción</th><th>Entidad</th><th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="date">{{ $log->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}</td>
                            <td class="actor">
                                @if ($log->user)
                                    <div class="nm">{{ $log->user->name }}</div>
                                    <div class="sub">{{ $log->user->email }}</div>
                                @elseif (!empty($log->changes['email']))
                                    <div class="nm">{{ $log->changes['email'] }}</div>
                                    <div class="sub">Invitado / no autenticado</div>
                                @else
                                    <div class="nm">Sistema</div>
                                @endif
                            </td>
                            <td>
                                <span class="chip ev-{{ AuditPresenter::actionGroup($log->action) }}">{{ AuditPresenter::actionLabel($log->action) }}</span>
                            </td>
                            <td class="ent">{{ AuditPresenter::entityLabel($log->auditable_type, $log->auditable_id) }}</td>
                            <td class="ip">{{ $log->ip ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty">No hay eventos de auditoría con esos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($logs->hasPages())
                <div class="pager">
                    <span>Mostrando {{ $logs->firstItem() }}–{{ $logs->lastItem() }} de {{ $logs->total() }}</span>
                    <div class="links">
                        @if ($logs->onFirstPage())
                            <span class="pg dis"><x-ui.icon name="chevron-left" class="i14" /></span>
                        @else
                            <a href="#" wire:click.prevent="previousPage" rel="prev"><x-ui.icon name="chevron-left" class="i14" /></a>
                        @endif
                        <span class="pg cur">{{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>
                        @if ($logs->hasMorePages())
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
