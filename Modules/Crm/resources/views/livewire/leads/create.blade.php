<div class="py-10">
    <x-ui.styles />
    <div class="mca-panel max-w-2xl mx-auto sm:px-6 lg:px-8 px-4">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('crm.leads.index') }}" class="btn btn-ghost btn-sm" title="Volver"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">Nuevo lead</h1>
                    <p class="mca-sub">Alta manual de un prospecto. Lo habitual es que los leads entren por el widget; esto es un complemento.</p>
                </div>
            </div>
        </div>

        <div class="card card-p fade">
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:180px">
                    <label>Nombre</label>
                    <input type="text" wire:model="first_name" maxlength="120" placeholder="Nombre">
                    @error('first_name') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:180px">
                    <label>Apellidos</label>
                    <input type="text" wire:model="last_name" maxlength="120" placeholder="Apellidos">
                    @error('last_name') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="field">
                <label>Correo</label>
                <input type="email" wire:model="email" maxlength="190" placeholder="persona@email.com">
                @error('email') <span class="mca-err">{{ $message }}</span> @enderror
                <div class="mca-help">Si el correo ya existe en la institución, se enriquece ese contacto (no se duplica).</div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:180px">
                    <label>WhatsApp / teléfono <span class="mca-muted" style="font-weight:400">· opcional</span></label>
                    <input type="text" wire:model="phone" maxlength="40" placeholder="+1 305 555 4821">
                    @error('phone') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:120px">
                    <label>País <span class="mca-muted" style="font-weight:400">· ISO 2</span></label>
                    <input type="text" wire:model="country" maxlength="2" placeholder="US" style="text-transform:uppercase">
                    @error('country') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:120px">
                    <label>Idioma</label>
                    <select wire:model="preferred_language">
                        <option value="es">Español</option>
                        <option value="en">English</option>
                    </select>
                    @error('preferred_language') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:180px">
                    <label>Área <span class="mca-muted" style="font-weight:400">· opcional</span></label>
                    <select wire:model="area">
                        <option value="">— Sin asignar —</option>
                        @foreach ($areas as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </select>
                    @error('area') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:180px">
                    <label>Meta <span class="mca-muted" style="font-weight:400">· opcional</span></label>
                    <input type="text" wire:model="goal" maxlength="120" placeholder="p. ej. actualizar, ascenso…">
                    @error('goal') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:150px">
                    <label>Interés</label>
                    <select wire:model="interest_level">
                        @foreach ($interestLevels as $l)
                            <option value="{{ $l->value }}">{{ $l->label() }}</option>
                        @endforeach
                    </select>
                    @error('interest_level') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="field" style="margin-bottom:0">
                <label>Nota inicial <span class="mca-muted" style="font-weight:400">· opcional</span></label>
                <textarea wire:model="notes" rows="3" maxlength="2000" placeholder="Contexto del prospecto…"></textarea>
                @error('notes') <span class="mca-err">{{ $message }}</span> @enderror
            </div>
        </div>

        <div style="margin-top:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">
                <span wire:loading.remove wire:target="save"><x-ui.icon name="check" class="ic" style="width:16px;height:16px" /> Crear lead</span>
                <span wire:loading wire:target="save"><span class="mca-spin"></span> Guardando…</span>
            </button>
            <a href="{{ route('crm.leads.index') }}" class="btn btn-ghost">Cancelar</a>
        </div>
    </div>
</div>
