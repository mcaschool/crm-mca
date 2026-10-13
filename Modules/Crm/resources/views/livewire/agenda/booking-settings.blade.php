<div class="ui-stack">
    @if ($notice !== '')
        <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ $notice }}</div>
    @endif

    {{-- Mi enlace de reservas --}}
    <form class="ui-card" wire:submit="saveLink" novalidate>
        <div class="ui-card-h"><x-ui.icon name="external-link" class="ui-card-ic" /><h2>{{ __('Mi enlace de reservas') }}</h2>
            @if ($bookingsCount > 0)<x-ui.badge tone="blue">{{ __('Reservas próximas: :n', ['n' => $bookingsCount]) }}</x-ui.badge>@endif
        </div>
        <div class="ui-card-p">
            <div class="ui-form-grid">
                <div class="ui-span-full">
                    <label class="ui-check"><input type="checkbox" wire:model="bookingEnabled"> {{ __('Recibir reservas públicas en mi Calendario') }}</label>
                    @error('bookingEnabled') <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                </div>
                <x-ui.field :label="__('Alias del enlace')" for="bk-slug" :error="$errors->first('bookingSlug')" :help="__('Solo minúsculas, números y guiones. Cambiarlo invalida el enlace anterior.')">
                    <input id="bk-slug" type="text" wire:model="bookingSlug" maxlength="80" placeholder="ana-garcia" autocapitalize="off" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('bookingSlug')])>
                </x-ui.field>
                <div class="ui-field">
                    <span class="ui-label">{{ __('Enlace público') }}</span>
                    @if ($publicUrl)
                        <div class="ui-copy" x-data="{ copied: false }">
                            <span class="ui-copy-text">{{ $publicUrl }}</span>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" :aria-label="copied ? @js(__('Copiado')) : @js(__('Copiar'))"
                                x-on:click="navigator.clipboard.writeText(@js($publicUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-show="!copied">{{ __('Copiar') }}</span><span x-show="copied" x-cloak>{{ __('Copiado') }}</span>
                            </button>
                        </div>
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="ui-link"><x-ui.icon name="external-link" /> {{ __('Vista previa') }}</a>
                    @else
                        <p class="ui-help">{{ __('Guarda un alias para obtener tu enlace.') }}</p>
                    @endif
                </div>
                @if ($profileProblems !== [])
                    <div class="ui-callout ui-tone--warning ui-callout--stack ui-span-full">
                        <b><x-ui.icon name="alert-triangle" /> {{ __('Para recibir reservas:') }}</b>
                        <ul class="ui-callout-list">@foreach ($profileProblems as $p)<li>{{ $p }}</li>@endforeach</ul>
                    </div>
                @endif
            </div>
        </div>
        <div class="ui-card-f ui-card-f--end">
            <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar enlace') }}</x-ui.button>
        </div>
    </form>

    {{-- Tipos de reunión --}}
    <section class="ui-card" aria-labelledby="mt-title">
        <div class="ui-card-h"><x-ui.icon name="calendar" class="ui-card-ic" /><h2 id="mt-title">{{ __('Tipos de reunión') }}</h2>
            <x-ui.badge tone="neutral" class="ui-badge--count">{{ $types->count() }}</x-ui.badge>
            <span class="sp"></span>
            @unless ($showTypeForm)
                <x-ui.button size="sm" icon="plus" wire:click="newType">{{ __('Nuevo tipo') }}</x-ui.button>
            @endunless
        </div>

        @if ($showTypeForm)
            <form wire:submit="saveType" novalidate class="ui-subform">
                <div class="ui-card-p">
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Nombre')" for="mt-name" required :error="$errors->first('name')">
                            <input id="mt-name" type="text" wire:model="name" maxlength="120" placeholder="{{ __('Consulta de admisiones') }}" @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Alias del enlace')" for="mt-slug" optional :error="$errors->first('slug')">
                            <input id="mt-slug" type="text" wire:model="slug" maxlength="80" placeholder="consulta-admisiones" autocapitalize="off" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('slug')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Duración (min)')" for="mt-dur" :error="$errors->first('duration')">
                            <input id="mt-dur" type="number" min="10" max="240" step="5" inputmode="numeric" wire:model="duration" @class(['ui-input', 'is-invalid' => $errors->has('duration')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Medio')" for="mt-medium" :error="$errors->first('medium')">
                            <select id="mt-medium" wire:model="medium" @class(['ui-select', 'is-invalid' => $errors->has('medium')])>
                                @foreach ($media as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Descripción')" for="mt-desc" optional>
                            <textarea id="mt-desc" wire:model="description" maxlength="2000" rows="3" class="ui-input ui-textarea"></textarea>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Instrucciones para el visitante')" for="mt-instr" optional>
                            <input id="mt-instr" type="text" wire:model="instructions" maxlength="500" class="ui-input">
                        </x-ui.field>
                        <x-ui.field :label="__('Programa de interés')" for="mt-prog" optional :error="$errors->first('programId')">
                            <select id="mt-prog" wire:model="programId" @class(['ui-select', 'is-invalid' => $errors->has('programId')])>
                                <option value="">—</option>
                                @foreach ($programs as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <div class="ui-field">
                            <span class="ui-label">{{ __('Visibilidad') }}</span>
                            <div class="ui-checks ui-checks--field">
                                <label class="ui-check"><input type="checkbox" wire:model="typeActive"> {{ __('Activo') }}</label>
                                <label class="ui-check"><input type="checkbox" wire:model="typePublic"> {{ __('Público') }}</label>
                            </div>
                        </div>
                        @error('typePublic') <div class="ui-callout ui-callout--sm ui-tone--warning ui-span-full"><x-ui.icon name="alert-triangle" /> <span>{{ $message }}</span></div> @enderror
                    </div>
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button wire:click="cancelType">{{ __('Cancelar') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar tipo') }}</x-ui.button>
                </div>
            </form>
        @endif

        <ul class="ui-list">
            @forelse ($types as $t)
                @php $url = $typeUrl($t); $problems = $typeProblems[$t->id] ?? []; @endphp
                <li wire:key="mt-{{ $t->id }}">
                    <div class="ui-list-item ui-list-item--wrap">
                        <span class="ui-list-main">
                            <span class="ui-list-title">{{ $t->name }}</span>
                            <span class="ui-person-line">
                                <span class="ui-list-meta">{{ $t->duration_minutes }} min · {{ $t->medium->label() }}</span>
                                <x-ui.badge :tone="$t->is_active ? 'success' : 'neutral'" dot>{{ $t->is_active ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                                <x-ui.badge :tone="$t->is_public ? 'blue' : 'neutral'">{{ $t->is_public ? __('Público') : __('No público') }}</x-ui.badge>
                            </span>
                            @if ($problems !== [])
                                <span class="ui-list-meta ui-text-warning"><x-ui.icon name="alert-triangle" /> {{ implode(' ', $problems) }}</span>
                            @elseif ($url && $t->is_public && $t->is_active)
                                <span class="ui-copy ui-copy--inline" x-data="{ copied: false }">
                                    <span class="ui-copy-text">{{ $url }}</span>
                                    <button type="button" class="ui-link ui-link--btn" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="!copied">{{ __('Copiar') }}</span><span x-show="copied" x-cloak>{{ __('Copiado') }}</span>
                                    </button>
                                </span>
                            @endif
                        </span>
                        <span class="ui-list-aside">
                            <x-ui.button size="sm" wire:click="toggleType({{ $t->id }}, 'is_public')">{{ $t->is_public ? __('Ocultar') : __('Publicar') }}</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="editType({{ $t->id }})" :title="__('Editar')" :aria-label="__('Editar :name', ['name' => $t->name])" />
                            <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="deleteType({{ $t->id }})" wire:confirm="{{ __('¿Eliminar este tipo de reunión? Las reservas existentes se conservan.') }}" :title="__('Eliminar')" :aria-label="__('Eliminar :name', ['name' => $t->name])" />
                        </span>
                    </div>
                </li>
            @empty
                <li class="ui-list-empty">{{ __('Aún no hay tipos de reunión. Crea el primero para publicarlo en tu enlace.') }}</li>
            @endforelse
        </ul>
    </section>
</div>
