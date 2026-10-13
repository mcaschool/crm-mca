<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header title="Nuevo lead" :back="route('crm.leads.index')" :back-label="__('Volver a leads')"
            subtitle="Alta manual de un prospecto. Lo habitual es que los leads entren por el widget; esto es un complemento." />

        <form wire:submit="save" novalidate class="ui-card">
            <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2>Datos de contacto</h2><span class="sp"></span><span class="ui-card-sub"><span class="ui-req" aria-hidden="true">*</span> obligatorio</span></div>
            <div class="ui-card-p">
                <div class="ui-form-grid">
                    <x-ui.field label="Nombre" for="lc-first_name" required :error="$errors->first('first_name')">
                        <input id="lc-first_name" type="text" wire:model="first_name" maxlength="120" placeholder="Nombre" autocomplete="given-name"
                            @class(['ui-input', 'is-invalid' => $errors->has('first_name')]) @error('first_name') aria-invalid="true" aria-describedby="lc-first_name-error" @enderror>
                    </x-ui.field>
                    <x-ui.field label="Apellidos" for="lc-last_name" :error="$errors->first('last_name')">
                        <input id="lc-last_name" type="text" wire:model="last_name" maxlength="120" placeholder="Apellidos" autocomplete="family-name"
                            @class(['ui-input', 'is-invalid' => $errors->has('last_name')]) @error('last_name') aria-invalid="true" aria-describedby="lc-last_name-error" @enderror>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" label="Correo" for="lc-email" required :error="$errors->first('email')"
                        help="Si el correo ya existe en la institución, se enriquece ese contacto (no se duplica).">
                        <input id="lc-email" type="email" wire:model="email" maxlength="190" placeholder="persona@email.com" autocomplete="email"
                            @class(['ui-input', 'is-invalid' => $errors->has('email')]) @error('email') aria-invalid="true" aria-describedby="lc-email-error" @enderror>
                    </x-ui.field>
                </div>
                <div class="ui-form-grid ui-form-grid--3">
                    <x-ui.field label="WhatsApp / teléfono" for="lc-phone" optional :error="$errors->first('phone')">
                        <input id="lc-phone" type="text" wire:model="phone" maxlength="40" placeholder="+1 305 555 4821" autocomplete="tel" inputmode="tel"
                            @class(['ui-input', 'is-invalid' => $errors->has('phone')]) @error('phone') aria-invalid="true" aria-describedby="lc-phone-error" @enderror>
                    </x-ui.field>
                    <x-ui.field label="País" for="lc-country" hint="ISO 2" :error="$errors->first('country')">
                        <input id="lc-country" type="text" wire:model="country" maxlength="2" placeholder="US" style="text-transform:uppercase" autocomplete="country"
                            @class(['ui-input', 'is-invalid' => $errors->has('country')]) @error('country') aria-invalid="true" aria-describedby="lc-country-error" @enderror>
                    </x-ui.field>
                    <x-ui.field label="Idioma" for="lc-lang" :error="$errors->first('preferred_language')">
                        <select id="lc-lang" wire:model="preferred_language" @class(['ui-select', 'is-invalid' => $errors->has('preferred_language')])>
                            <option value="es">Español</option>
                            <option value="en">English</option>
                        </select>
                    </x-ui.field>
                </div>
            </div>

            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="sparkles" class="ui-card-ic" /><h2>Interés comercial</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid ui-form-grid--3">
                    <x-ui.field label="Área" for="lc-area" optional :error="$errors->first('area')">
                        <select id="lc-area" wire:model="area" @class(['ui-select', 'is-invalid' => $errors->has('area')])>
                            <option value="">— Sin asignar —</option>
                            @foreach ($areas as $a)
                                <option value="{{ $a }}">{{ $a }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Meta" for="lc-goal" optional :error="$errors->first('goal')">
                        <input id="lc-goal" type="text" wire:model="goal" maxlength="120" placeholder="p. ej. actualizar, ascenso…"
                            @class(['ui-input', 'is-invalid' => $errors->has('goal')]) @error('goal') aria-invalid="true" aria-describedby="lc-goal-error" @enderror>
                    </x-ui.field>
                    <x-ui.field label="Interés" for="lc-interest" :error="$errors->first('interest_level')">
                        <select id="lc-interest" wire:model="interest_level" @class(['ui-select', 'is-invalid' => $errors->has('interest_level')])>
                            @foreach ($interestLevels as $l)
                                <option value="{{ $l->value }}">{{ $l->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" label="Nota inicial" for="lc-notes" optional :error="$errors->first('notes')">
                        <textarea id="lc-notes" wire:model="notes" rows="3" maxlength="2000" placeholder="Contexto del prospecto…"
                            @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('notes')]) @error('notes') aria-invalid="true" aria-describedby="lc-notes-error" @enderror></textarea>
                    </x-ui.field>
                </div>
            </div>

            <div class="ui-card-f ui-card-f--end">
                <x-ui.button :href="route('crm.leads.index')">Cancelar</x-ui.button>
                <button type="submit" class="ui-btn ui-btn--primary" wire:loading.attr="disabled" wire:target="save">
                    <span class="ui-btn-label" wire:loading.remove wire:target="save"><x-ui.icon name="check" /> Crear lead</span>
                    <span wire:loading wire:target="save"><span class="ui-spin"></span> Guardando…</span>
                </button>
            </div>
        </form>
    </div>
</div>
