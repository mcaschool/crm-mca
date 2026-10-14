<div class="mca-page">
    <x-ui.page-header :title="__('Asesores Inteligentes')" :count="count($advisors)" :subtitle="__('Asesores que atienden en la web y en los canales sociales, con su idioma, modelo y conocimiento.')">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" :href="route('advisors.create')">{{ __('Crear asesor') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    <div class="ui-grid">
        @forelse ($advisors as $a)
            <article class="ui-card ui-entity" wire:key="adv-{{ $a['id'] }}">
                <div class="ui-entity-h">
                    <x-ui.avatar :name="$a['name']" :src="$a['avatar']" :seed="$a['id']" size="lg" />
                    <div class="ui-person-text">
                        <h2 class="ui-entity-title">{{ $a['name'] }}</h2>
                        <div class="ui-person-line">
                            @if ($a['type'] === 'human')
                                <x-ui.badge tone="deep" icon="user">{{ __('Humano') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="blue" icon="bot">{{ __('IA') }}</x-ui.badge>
                            @endif
                            <x-ui.badge :tone="$a['status'] === 'active' ? 'success' : 'neutral'" dot>{{ $a['status'] === 'active' ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                        </div>
                    </div>
                </div>

                <div class="ui-entity-b">
                    <dl class="ui-meta">
                        <dt>{{ __('Idioma') }}</dt><dd>{{ $a['language'] }}</dd>
                        @if ($a['type'] !== 'human')
                            <dt>{{ __('Modelo') }}</dt>
                            <dd>@if ($a['model'])<span class="ui-mono">{{ $a['model'] }}</span>@else<span class="t-nil">{{ __('Sin proceso de IA') }}</span>@endif</dd>
                        @endif
                        <dt>{{ __('Conocimiento') }}</dt><dd>{{ $a['docs'] }} {{ $a['docs'] === 1 ? __('documento') : __('documentos') }}</dd>
                    </dl>
                </div>

                @if ($canManage)
                    <div class="ui-card-f">
                        <x-ui.button size="sm" icon="pencil" :href="route('advisors.edit', $a['id'])">{{ __('Configurar') }}</x-ui.button>
                    </div>
                @endif
            </article>
        @empty
            <div class="ui-card"><div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="bot" /></div><b>{{ __('Aún no hay asesores.') }}</b></div></div>
        @endforelse
    </div>
</div>
