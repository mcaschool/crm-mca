<div class="mca-page ui-stack">
    <x-ui.page-header :title="__('Ficha del Asesor Académico')" :subtitle="__('Nombre, foto y bases de conocimiento del asesor. El widget y los saludos leen estos valores.')">
        <x-slot:actions>
            <livewire:ai.advisor-selector />
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    @if ($bot === null)
        <div class="ui-alert ui-alert--flush ui-tone--danger" role="alert"><x-ui.icon name="alert-triangle" /> {{ __('No hay un bot activo que configurar.') }}</div>
    @else
        {{-- 1) Identidad: nombre + avatar --}}
        <section class="ui-card" aria-labelledby="cfg-identity-t">
            <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="cfg-identity-t">{{ __('Identidad') }}</h2></div>
            <div class="ui-card-p ui-identity">
                {{-- Avatar --}}
                <div class="ui-photo">
                    @if ($avatar)
                        <span class="ui-avatar ui-avatar--photo ui-tone--blue"><img src="{{ $avatar->temporaryUrl() }}" alt="{{ __('Vista previa de la foto') }}"></span>
                    @elseif ($avatarUrl)
                        <span class="ui-avatar ui-avatar--photo ui-tone--blue"><img src="{{ $avatarUrl }}" alt="{{ $bot->assistant_name }}"></span>
                    @else
                        {{-- Avatar por defecto: icono graduation-cap --}}
                        <span class="ui-avatar ui-avatar--photo ui-tone--blue" aria-hidden="true"><x-ui.icon name="graduation-cap" /></span>
                    @endif
                    <label class="ui-btn ui-btn--secondary ui-btn--sm">
                        <x-ui.icon name="upload" /> {{ __('Elegir foto') }}
                        <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                    </label>
                    <span wire:loading wire:target="avatar" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                    @error('avatar') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                    @if ($avatar || $avatarUrl)
                        <div class="ui-actions-row">
                            @if ($avatar)
                                <x-ui.button size="sm" variant="primary" icon="check" wire:click="saveAvatar">{{ __('Guardar foto') }}</x-ui.button>
                            @endif
                            @if ($avatarUrl)
                                <x-ui.button size="sm" variant="ghost" wire:click="removeAvatar">{{ __('Quitar') }}</x-ui.button>
                            @endif
                        </div>
                    @endif
                    <p class="ui-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB') }}</p>
                </div>

                {{-- Nombre --}}
                <div class="ui-stack">
                    <x-ui.field :label="__('Nombre del asesor')" for="cfg-name" required :error="$errors->first('name')"
                                :help="__('Este nombre aparece en el encabezado del widget, junto a cada respuesta y en el saludo.')">
                        <input id="cfg-name" type="text" wire:model="name" maxlength="60" @if ($errors->has('name')) aria-describedby="cfg-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                    </x-ui.field>
                    <div class="ui-form-actions">
                        <x-ui.button variant="primary" icon="check" wire:click="saveName">{{ __('Guardar nombre') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </section>

        {{-- 2) Bases de conocimiento --}}
        <section class="ui-card" aria-labelledby="cfg-kb-t">
            <div class="ui-card-h"><x-ui.icon name="book-open" class="ui-card-ic" /><h2 id="cfg-kb-t">{{ __('Bases de conocimiento') }}</h2>
                <span class="ui-badge ui-badge--count ui-tone--neutral">{{ count($sources) }}</span>
                <span class="sp"></span>
                <button type="button" wire:click="sync" wire:loading.attr="disabled" class="ui-btn ui-btn--secondary ui-btn--sm">
                    <span wire:loading.remove wire:target="sync" class="ui-btn-label"><x-ui.icon name="refresh" /> {{ __('Re-sincronizar') }}</span>
                    <span wire:loading.inline-flex wire:target="sync" class="ui-btn-label"><span class="ui-spin"></span> {{ __('Sincronizando…') }}</span>
                </button>
            </div>
            <div class="ui-card-p">
                <p class="ui-card-desc">{{ __('Hechos que el asesor responde. Al subir un .md se actualiza por código y se sincroniza solo.') }}</p>
                <div class="ui-actions-row ui-actions-row--center">
                    <label class="ui-btn ui-btn--secondary ui-btn--sm"><x-ui.icon name="upload" /> {{ __('Elegir archivos .md') }}
                        <input type="file" wire:model="docs" accept=".md" multiple class="hidden">
                    </label>
                    <span wire:loading wire:target="docs" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                </div>
                @error('docs') <p class="ui-error ui-actions-row--top" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror

                @if (count($docs))
                    <div class="ui-callout ui-callout--stack ui-actions-row--top">
                        <ul class="ui-callout-list">
                            @foreach ($docs as $d)
                                <li>{{ $d->getClientOriginalName() }}</li>
                            @endforeach
                        </ul>
                        <x-ui.button size="sm" variant="primary" icon="upload" wire:click="uploadKnowledge">{{ __('Subir y sincronizar') }}</x-ui.button>
                    </div>
                @endif
            </div>

            <ul class="ui-list ui-list--sep">
                @forelse ($sources as $source)
                    <li wire:key="ks-{{ $source->id }}">
                        <div class="ui-list-item">
                            <span class="ui-list-ic"><x-ui.icon name="file-text" /></span>
                            <span class="ui-list-main">
                                <span class="ui-list-title">{{ $source->name }}</span>
                                <span class="ui-list-meta"><span class="ui-mono">{{ $source->code }}</span> · {{ __('Última sincronización') }}: {{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : __('nunca') }}</span>
                            </span>
                            <span class="ui-list-aside">
                                <x-ui.badge :tone="$source->status === 'active' ? 'success' : 'neutral'" dot>{{ $source->status === 'active' ? __('Activa') : __($source->status) }}</x-ui.badge>
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="ui-list-empty">{{ __('Sin documentos. Sube uno o varios .md para cargar el conocimiento del asesor.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
