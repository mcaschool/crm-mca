<div class="mca-page">
    <x-ui.page-header :title="$editing ? __('Editar programa') : __('Nuevo programa')" :subtitle="__('Datos con los que el emparejador y los asesores recomiendan el programa. Los textos visibles se guardan en español y en inglés.')" :back="route('catalog.programs.index')" :back-label="__('Volver al catálogo')" />

    <form wire:submit="save" class="ui-stack ui-narrow" novalidate>
        {{-- Identificación --}}
        <section class="ui-card" aria-labelledby="pf-id-title">
            <div class="ui-card-h"><x-ui.icon name="book-open" class="ui-card-ic" /><h2 id="pf-id-title">{{ __('Identificación') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid">
                    <x-ui.field :label="__('Código')" for="pf-code" required :error="$errors->first('code')">
                        <input id="pf-code" type="text" wire:model="code" maxlength="40" autocapitalize="characters" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('code')]) @error('code') aria-describedby="pf-code-error" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('Línea')" for="pf-line" :error="$errors->first('line')" :help="__('La misma línea que el Centro de Conocimiento: agrupa sus programas y su conocimiento en «Por agente».')">
                        <select id="pf-line" wire:model.live="line" @class(['ui-select', 'is-invalid' => $errors->has('line')]) aria-describedby="pf-line-help">
                            <option value="">{{ __('— sin línea —') }}</option>
                            @foreach ($lines as $slug => $label)
                                <option value="{{ $slug }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    {{-- Las áreas solo existen en Microcredenciales: el campo solo se muestra (y se exige) ahí. --}}
                    @if ($hasAreas)
                        <x-ui.field class="ui-span-full" :label="__('Área')" for="pf-area" required :error="$errors->first('category_id')">
                            <select id="pf-area" wire:model="category_id" @class(['ui-select', 'is-invalid' => $errors->has('category_id')]) @error('category_id') aria-describedby="pf-area-error" @enderror>
                                <option value="">{{ __('— elige un área —') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name_es }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif

                    <x-ui.field :label="__('Estado')" for="pf-status" required :error="$errors->first('status')">
                        <select id="pf-status" wire:model="status" @class(['ui-select', 'is-invalid' => $errors->has('status')])>
                            <option value="active">{{ __('Activo') }}</option>
                            <option value="inactive">{{ __('Inactivo') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('Orden (peso)')" for="pf-order" :error="$errors->first('display_order')">
                        <input id="pf-order" type="number" inputmode="numeric" wire:model="display_order" @class(['ui-input', 'is-invalid' => $errors->has('display_order')])>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" :label="__('URL (ficha en la web)')" for="pf-url" :error="$errors->first('url')">
                        <input id="pf-url" type="text" inputmode="url" wire:model="url" maxlength="500" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('url')]) @error('url') aria-describedby="pf-url-error" @enderror>
                    </x-ui.field>
                </div>
            </div>
        </section>

        {{-- Textos visibles: español a la izquierda, inglés a la derecha --}}
        <section class="ui-card" aria-labelledby="pf-txt-title">
            <div class="ui-card-h"><x-ui.icon name="globe" class="ui-card-ic" /><h2 id="pf-txt-title">{{ __('Nombre y descripción') }}</h2>
                <span class="sp"></span><span class="ui-card-sub">{{ __('Español · Inglés') }}</span>
            </div>
            <div class="ui-card-p">
                <div class="ui-form-grid">
                    <x-ui.field :label="__('Nombre (ES)')" for="pf-name-es" required :error="$errors->first('name_es')">
                        <input id="pf-name-es" type="text" wire:model="name_es" maxlength="200" @class(['ui-input', 'is-invalid' => $errors->has('name_es')]) @error('name_es') aria-describedby="pf-name-es-error" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('Nombre (EN)')" for="pf-name-en" :error="$errors->first('name_en')">
                        <input id="pf-name-en" type="text" wire:model="name_en" maxlength="200" placeholder="{{ __('completar en inglés') }}" @class(['ui-input', 'is-invalid' => $errors->has('name_en')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Descripción corta (ES)')" for="pf-desc-es" :error="$errors->first('short_description_es')">
                        <textarea id="pf-desc-es" wire:model="short_description_es" rows="4" @class(['ui-input', 'ui-textarea', 'is-invalid' => $errors->has('short_description_es')])></textarea>
                    </x-ui.field>
                    <x-ui.field :label="__('Descripción corta (EN)')" for="pf-desc-en" :error="$errors->first('short_description_en')">
                        <textarea id="pf-desc-en" wire:model="short_description_en" rows="4" placeholder="{{ __('completar en inglés') }}" @class(['ui-input', 'ui-textarea', 'is-invalid' => $errors->has('short_description_en')])></textarea>
                    </x-ui.field>
                    <x-ui.field :label="__('Duración (ES)')" for="pf-dur-es" :error="$errors->first('duration_es')">
                        <input id="pf-dur-es" type="text" wire:model="duration_es" maxlength="80" @class(['ui-input', 'is-invalid' => $errors->has('duration_es')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Duración (EN)')" for="pf-dur-en" :error="$errors->first('duration_en')">
                        <input id="pf-dur-en" type="text" wire:model="duration_en" maxlength="80" @class(['ui-input', 'is-invalid' => $errors->has('duration_en')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Modalidad (ES)')" for="pf-mod-es" :error="$errors->first('modality_es')">
                        <input id="pf-mod-es" type="text" wire:model="modality_es" maxlength="80" @class(['ui-input', 'is-invalid' => $errors->has('modality_es')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Modalidad (EN)')" for="pf-mod-en" :error="$errors->first('modality_en')">
                        <input id="pf-mod-en" type="text" wire:model="modality_en" maxlength="80" @class(['ui-input', 'is-invalid' => $errors->has('modality_en')])>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" :label="__('Microcredencial que otorga (EN)')" for="pf-cred" :error="$errors->first('credential_en')">
                        <input id="pf-cred" type="text" wire:model="credential_en" maxlength="200" @class(['ui-input', 'is-invalid' => $errors->has('credential_en')])>
                    </x-ui.field>
                </div>
            </div>
        </section>

        {{-- Emparejador --}}
        <section class="ui-card" aria-labelledby="pf-match-title">
            <div class="ui-card-h"><x-ui.icon name="filter" class="ui-card-ic" /><h2 id="pf-match-title">{{ __('Emparejador') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid ui-form-grid--3">
                    <x-ui.field :label="__('Nivel')" for="pf-level" :error="$errors->first('level')">
                        <input id="pf-level" type="text" wire:model="level" maxlength="40" placeholder="inicial/intermedio/avanzado" @class(['ui-input', 'is-invalid' => $errors->has('level')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Meta')" for="pf-goal" :error="$errors->first('goal')">
                        <input id="pf-goal" type="text" wire:model="goal" maxlength="80" @class(['ui-input', 'is-invalid' => $errors->has('goal')])>
                    </x-ui.field>
                    <x-ui.field :label="__('Perfil')" for="pf-profile" :error="$errors->first('profile')">
                        <input id="pf-profile" type="text" wire:model="profile" maxlength="120" @class(['ui-input', 'is-invalid' => $errors->has('profile')])>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" :label="__('Etiquetas (separadas por coma; dominante-*, tema-*)')" for="pf-tags">
                        <input id="pf-tags" type="text" wire:model="tagsCsv" spellcheck="false" class="ui-input">
                    </x-ui.field>
                </div>
            </div>
        </section>

        <div class="ui-form-actions ui-form-actions--end">
            <x-ui.button :href="route('catalog.programs.index')">{{ __('Cancelar') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar') }}</x-ui.button>
        </div>
    </form>
</div>
