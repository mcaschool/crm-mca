@php
    $fmt = fn ($d) => $d?->copy()->setTimezone($tz)->translatedFormat('j M Y · H:i');
@endphp
<div class="mca-page" wire:key="publisher-history">
    <x-ui.page-header :title="__('Publicador')" :subtitle="__('Historial de todo lo gestionado desde MCA CRM, con el resultado de cada red.')" />
    @include('social::partials.publisher-tabs')

    @if ($notice)
        <div class="ui-alert ui-tone--{{ $noticeTone ?? 'success' }}" role="status"><x-ui.icon name="{{ $noticeTone === 'success' ? 'check' : 'alert-triangle' }}" /> {{ $notice }}</div>
    @endif

    <div class="ui-card ui-card--list">
        <div class="ui-toolbar">
            <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Buscar en la descripción').'…'" :aria-label="__('Buscar en la descripción')" />
            <x-ui.filters :active="collect([$network, $channel, $status, $from, $to])->filter(fn ($v) => $v !== '')->count()">
                <x-ui.select filter :active="$network !== ''" wire:model.live="network" :aria-label="__('Filtrar por red')">
                    <option value="">{{ __('Red · todas') }}</option>
                    <option value="facebook">Facebook</option>
                    <option value="instagram">Instagram</option>
                </x-ui.select>
                <x-ui.select filter :active="$channel !== ''" wire:model.live="channel" :aria-label="__('Filtrar por cuenta')">
                    <option value="">{{ __('Cuenta · todas') }}</option>
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}">{{ $ch->provider === 'messenger' ? 'Facebook' : 'Instagram' }} · {{ $ch->display_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select filter :active="$status !== ''" wire:model.live="status" :aria-label="__('Filtrar por estado')">
                    <option value="">{{ __('Estado · todos') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </x-ui.select>
                <input type="date" wire:model.live="from" @class(['ui-input', 'ui-input--filter', 'is-active' => $from !== '']) aria-label="{{ __('Desde la fecha') }}" title="{{ __('Desde') }}">
                <input type="date" wire:model.live="to" @class(['ui-input', 'ui-input--filter', 'is-active' => $to !== '']) aria-label="{{ __('Hasta la fecha') }}" title="{{ __('Hasta') }}">
                @if ($filtered)
                    <x-ui.button variant="ghost" size="sm" wire:click="clearFilters">{{ __('Limpiar') }}</x-ui.button>
                @endif
            </x-ui.filters>
        </div>

        <div class="mca-table-scroll">
            <table class="ui-table ui-table--stack-aside">
                <thead>
                    <tr>
                        <th class="rt-sticky">{{ __('Publicación') }}</th>
                        <th>{{ __('Estado') }}</th>
                        <th>{{ __('Redes') }}</th>
                        <th>{{ __('Fecha') }}</th>
                        <th>{{ __('Responsable') }}</th>
                        <th><span class="ui-sr">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        <tr wire:key="ph-{{ $post->id }}" class="is-link" wire:click="openPost({{ $post->id }})">
                            <td class="rt-primary rt-sticky">
                                <div class="ui-person">
                                    @include('social::partials.post-thumb', ['post' => $post])
                                    <div class="ui-person-text">
                                        <span class="ui-person-name">{{ $post->contentLabel() }} · {{ $post->media_type === 'video' ? __('Video') : __('Imagen') }}</span>
                                        <span class="ui-person-mail ui-pub-excerpt">{{ $post->caption ? \Illuminate\Support\Str::limit($post->caption, 90) : ($post->content_type === 'story' ? __('Historia sin descripción') : __('Sin descripción')) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="rt-aside" data-label="{{ __('Estado') }}">
                                <x-ui.badge :tone="$post->statusTone()" dot>{{ $post->statusLabel() }}</x-ui.badge>
                            </td>
                            <td data-label="{{ __('Redes') }}">
                                <span class="ui-pub-netstat">
                                    @foreach ($post->destinationNetworks() as $n)
                                        @php $t = $post->targets->firstWhere('network', $n); @endphp
                                        <span class="ui-pub-netstat-i ui-tone--{{ $t?->statusTone() ?? 'neutral' }}" title="{{ \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n] }}: {{ $t?->statusLabel() ?? __('Pendiente') }}">
                                            @include('social::partials.provider-icon', ['provider' => $n, 'size' => 15])<i aria-hidden="true"></i>
                                            <span class="ui-sr">{{ \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n] }}: {{ $t?->statusLabel() ?? __('Pendiente') }}</span>
                                        </span>
                                    @endforeach
                                </span>
                            </td>
                            <td data-label="{{ __('Fecha') }}">
                                @if ($post->published_at)
                                    <span>{{ $fmt($post->published_at) }}</span><span class="t-sub">{{ __('Publicada') }}</span>
                                @elseif ($post->scheduled_at)
                                    <span>{{ $fmt($post->scheduled_at) }}</span><span class="t-sub">{{ __('Programada') }}</span>
                                @else
                                    <span>{{ $fmt($post->created_at) }}</span><span class="t-sub">{{ __('Creada') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('Responsable') }}"><span class="t-2">{{ $post->creator?->name ?? '—' }}</span></td>
                            <td class="rt-actions">
                                <div class="t-actions">
                                    <x-ui.button size="sm" icon="eye" wire:click.stop="openPost({{ $post->id }})">{{ __('Ver') }}</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rt-empty">
                                <div class="ui-empty">
                                    <div class="ui-empty-ic"><x-ui.icon name="image" /></div>
                                    @if ($filtered)
                                        <b>{{ __('Ninguna publicación coincide.') }}</b>
                                        {{ __('Prueba con otra búsqueda o quita algún filtro.') }}
                                    @else
                                        <b>{{ __('Aún no hay publicaciones.') }}</b>
                                        {{ __('Lo que publiques o programes desde MCA CRM aparecerá aquí con su resultado.') }}
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$posts" />
    </div>

    @include('social::partials.post-detail')
</div>
