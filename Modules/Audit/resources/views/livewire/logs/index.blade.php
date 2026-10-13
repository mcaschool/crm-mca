@use('Modules\Audit\Support\AuditPresenter')
@php
    // Grupo de la acción (AuditPresenter) → tono del sistema de diseño.
    $tones = ['ok' => 'success', 'info' => 'blue', 'warn' => 'warning', 'danger' => 'danger', 'neutral' => 'neutral'];
    $activeFilters = collect([$action, $actor, $from, $to])->filter(fn ($v) => $v !== '')->count();
@endphp
<div class="mca-page">
    <x-ui.page-header :title="__('Auditoría de seguridad')" :count="$logs->total()" :subtitle="__('Accesos, cambios de permisos y consultas de datos personales, con su autor y su origen.')" />

    <div class="ui-card ui-card--list">
        <div class="ui-toolbar">
            <x-ui.filters :active="$activeFilters">
                <x-ui.select filter :active="$action !== ''" wire:model.live="action" aria-label="Filtrar por tipo de acción">
                    <option value="">Acción · todas</option>
                    @foreach ($actionOptions as $opt)
                        <option value="{{ $opt }}">{{ AuditPresenter::actionLabel($opt) }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select filter :active="$actor !== ''" wire:model.live="actor" aria-label="Filtrar por actor">
                    <option value="">Actor · todos</option>
                    @foreach ($actorOptions as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </x-ui.select>

                <input type="date" @class(['ui-input ui-input--filter', 'is-active' => $from !== '']) wire:model.live="from" aria-label="Desde la fecha" title="Desde" max="{{ now()->format('Y-m-d') }}">
                <input type="date" @class(['ui-input ui-input--filter', 'is-active' => $to !== '']) wire:model.live="to" aria-label="Hasta la fecha" title="Hasta" max="{{ now()->format('Y-m-d') }}">

                @if ($activeFilters > 0)
                    <x-ui.button variant="ghost" icon="x" wire:click="clearFilters">Limpiar</x-ui.button>
                @endif
            </x-ui.filters>
        </div>

        <div class="mca-table-scroll">
        <table class="ui-table ui-table--stack-aside">
            <thead>
                <tr>
                    <th class="rt-sticky">Fecha / hora</th><th>Actor</th><th>Acción</th><th>Entidad</th><th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr wire:key="log-{{ $log->id }}">
                        <td class="rt-sticky t-2" data-label="Fecha / hora">{{ $log->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}</td>
                        <td class="rt-primary">
                            <div class="ui-person">
                                @if ($log->user)
                                    <x-ui.avatar :name="$log->user->name" :seed="$log->user->id" />
                                    <div class="ui-person-text">
                                        <span class="ui-person-name">{{ $log->user->name }}</span>
                                        <span class="ui-person-mail">{!! str_replace('@', '@<wbr>', e($log->user->email)) !!}</span>
                                    </div>
                                @elseif (! empty($log->changes['email']))
                                    <span class="ui-avatar ui-tone--neutral" aria-hidden="true"><x-ui.icon name="user" /></span>
                                    <div class="ui-person-text">
                                        <span class="ui-person-name">{!! str_replace('@', '@<wbr>', e($log->changes['email'])) !!}</span>
                                        <span class="ui-person-mail">Invitado / no autenticado</span>
                                    </div>
                                @else
                                    <span class="ui-avatar ui-tone--deep" aria-hidden="true"><x-ui.icon name="cpu" /></span>
                                    <div class="ui-person-text"><span class="ui-person-name">Sistema</span></div>
                                @endif
                            </div>
                        </td>
                        <td class="rt-aside" data-label="Acción">
                            <x-ui.badge :tone="$tones[AuditPresenter::actionGroup($log->action)] ?? 'neutral'" dot>{{ AuditPresenter::actionLabel($log->action) }}</x-ui.badge>
                        </td>
                        <td class="rt-shrink t-2" data-label="Entidad">{{ AuditPresenter::entityLabel($log->auditable_type, $log->auditable_id) }}</td>
                        <td class="t-mono t-3" data-label="IP">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="rt-empty">
                            <div class="ui-empty">
                                <div class="ui-empty-ic"><x-ui.icon name="shield" /></div>
                                <b>No hay eventos de auditoría con esos filtros.</b>
                                Cambia el periodo o quita algún filtro.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        <x-ui.pager :paginator="$logs" />
    </div>
</div>
