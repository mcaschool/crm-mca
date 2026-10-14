@php
    $fmt = fn ($d) => $d?->copy()->setTimezone($tz)->translatedFormat('D j M Y · H:i');
    $rel = fn ($d) => $d?->diffForHumans();
@endphp
<div class="mca-page" wire:key="publisher-scheduled">
    <x-ui.page-header :title="__('Publicador')" :subtitle="__('Lo que aún no ha salido: programadas, en curso y borradores.')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" :href="route('social.publisher')">{{ __('Nueva publicación') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @include('social::partials.publisher-tabs')

    @if ($notice)
        <div class="ui-alert ui-tone--{{ $noticeTone ?? 'success' }}" role="status"><x-ui.icon name="{{ $noticeTone === 'success' ? 'check' : 'alert-triangle' }}" /> {{ $notice }}</div>
    @endif

    <div class="ui-stack">
        {{-- Programadas --}}
        <section class="ui-card" aria-labelledby="ps-sched">
            <div class="ui-card-h"><x-ui.icon name="clock" class="ui-card-ic" /><h2 id="ps-sched">{{ __('Programadas') }}</h2>
                <x-ui.badge tone="neutral" class="ui-badge--count">{{ $scheduled->count() }}</x-ui.badge>
                <span class="sp"></span><span class="ui-card-sub"><x-ui.icon name="globe" /> {{ __('Horas en :tz', ['tz' => $tz]) }}</span>
            </div>
            @if ($scheduled->isEmpty())
                <div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="clock" /></div><b>{{ __('No hay publicaciones programadas.') }}</b>{{ __('Crea una publicación y elige «Programar» para que MCA CRM la publique en la fecha indicada.') }}</div>
            @else
                <ul class="ui-list">
                    @foreach ($scheduled as $post)
                        <li wire:key="ps-{{ $post->id }}">
                            <div class="ui-list-item ui-list-item--wrap ui-pub-row">
                                @include('social::partials.post-thumb', ['post' => $post])
                                <button type="button" class="ui-pub-row-main" wire:click="openPost({{ $post->id }})">
                                    <span class="ui-list-title">{{ $fmt($post->scheduled_at) }}</span>
                                    <span class="ui-list-meta">{{ $rel($post->scheduled_at) }} · {{ $post->contentLabel() }} · {{ $post->caption ? \Illuminate\Support\Str::limit($post->caption, 70) : __('Sin descripción') }}</span>
                                    <span class="ui-pub-targets">@foreach ($post->destinationNetworks() as $n)<span>@include('social::partials.provider-icon', ['provider' => $n, 'size' => 14]) {{ \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n] }}</span>@endforeach</span>
                                </button>
                                <span class="ui-list-aside ui-list-aside--wrap">
                                    <x-ui.button size="sm" icon="pencil" :href="route('social.publisher', ['editar' => $post->id])">{{ __('Editar') }}</x-ui.button>
                                    <x-ui.button size="sm" icon="clock" wire:click="startReschedule({{ $post->id }})">{{ __('Cambiar fecha') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="danger" icon="x" wire:click="cancelPost({{ $post->id }})" wire:confirm="{{ __('¿Cancelar la programación? No se publicará.') }}" :aria-label="__('Cancelar programación')" />
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- En curso --}}
        @if ($inFlight->isNotEmpty())
            <section class="ui-card" aria-labelledby="ps-flight">
                <div class="ui-card-h"><x-ui.icon name="refresh" class="ui-card-ic" /><h2 id="ps-flight">{{ __('En curso') }}</h2>
                    <x-ui.badge tone="neutral" class="ui-badge--count">{{ $inFlight->count() }}</x-ui.badge></div>
                <p class="ui-card-intro">{{ __('Ya se están publicando: no admiten cambios. Los videos que Meta sigue procesando se completan solos.') }}</p>
                <ul class="ui-list">
                    @foreach ($inFlight as $post)
                        <li wire:key="pf-{{ $post->id }}">
                            <button type="button" class="ui-list-item ui-pub-row" wire:click="openPost({{ $post->id }})">
                                @include('social::partials.post-thumb', ['post' => $post])
                                <span class="ui-list-main">
                                    <span class="ui-list-title">{{ $post->contentLabel() }} · {{ $post->caption ? \Illuminate\Support\Str::limit($post->caption, 70) : __('Sin descripción') }}</span>
                                    <span class="ui-list-meta">{{ __('Desde :date', ['date' => $fmt($post->scheduled_at ?? $post->updated_at)]) }}</span>
                                </span>
                                <x-ui.badge :tone="$post->statusTone()" dot>{{ $post->statusLabel() }}</x-ui.badge>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Borradores --}}
        <section class="ui-card" aria-labelledby="ps-drafts">
            <div class="ui-card-h"><x-ui.icon name="file-text" class="ui-card-ic" /><h2 id="ps-drafts">{{ __('Borradores') }}</h2>
                <x-ui.badge tone="neutral" class="ui-badge--count">{{ $drafts->count() }}</x-ui.badge></div>
            @if ($drafts->isEmpty())
                <div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="file-text" /></div><b>{{ __('No hay borradores.') }}</b>{{ __('Usa «Guardar borrador» en el editor para preparar contenido sin publicarlo todavía.') }}</div>
            @else
                <ul class="ui-list">
                    @foreach ($drafts as $post)
                        <li wire:key="pd-{{ $post->id }}">
                            <div class="ui-list-item ui-list-item--wrap ui-pub-row">
                                @include('social::partials.post-thumb', ['post' => $post])
                                <button type="button" class="ui-pub-row-main" wire:click="openPost({{ $post->id }})">
                                    <span class="ui-list-title">{{ $post->contentLabel() }} · {{ $post->caption ? \Illuminate\Support\Str::limit($post->caption, 70) : __('Sin descripción') }}</span>
                                    <span class="ui-list-meta">{{ __('Editado :when por :name', ['when' => $rel($post->updated_at), 'name' => $post->updater?->name ?? $post->creator?->name ?? '—']) }}@unless ($post->hasMedia()) · <span class="ui-text-warning">{{ __('sin archivo') }}</span>@endunless</span>
                                </button>
                                <span class="ui-list-aside ui-list-aside--wrap">
                                    <x-ui.button size="sm" icon="pencil" :href="route('social.publisher', ['editar' => $post->id])">{{ __('Editar') }}</x-ui.button>
                                    <x-ui.button size="sm" icon="clock" wire:click="startReschedule({{ $post->id }})">{{ __('Programar') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="danger" icon="trash" wire:click="deleteDraft({{ $post->id }})" wire:confirm="{{ __('¿Eliminar este borrador? Se borra también su archivo.') }}" :aria-label="__('Eliminar borrador')" />
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @include('social::partials.post-detail')
</div>
