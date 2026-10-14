<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header :title="$editing ? __('Editar usuario') : __('Crear usuario')" :subtitle="__('Perfil de un asesor humano del equipo.')" :back="route('users.index')" :backLabel="__('Usuarios')" />

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <div class="ui-stack">
            {{-- Enlace de invitacion (acceso) --}}
            @if ($invitationLink)
                <div class="ui-callout ui-tone--blue ui-callout--stack" role="status">
                    <b><x-ui.icon name="mail" /> {{ __('Enlace de acceso') }}</b>
                    <span>{{ __('Comparte este enlace con la persona para que fije su contraseña. Caduca por seguridad; puedes generar otro. (En producción se enviaría por correo.)') }}</span>
                    <div class="ui-inline">
                        <input type="text" readonly value="{{ $invitationLink }}" id="invlink" class="ui-input ui-mono" aria-label="{{ __('Enlace de acceso') }}">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('invlink').value);this.textContent=@js(__('Copiado'))">{{ __('Copiar') }}</button>
                    </div>
                </div>
            @endif

            {{-- Foto de perfil (solo al editar) --}}
            @if ($editing)
                <section class="ui-card" aria-labelledby="uf-photo-title">
                    <div class="ui-card-h"><x-ui.icon name="image" class="ui-card-ic" /><h2 id="uf-photo-title">{{ __('Foto de perfil') }}</h2></div>
                    <div class="ui-card-p">
                        <div class="ui-media">
                            <x-ui.avatar :name="$name" :src="$avatar ? $avatar->temporaryUrl() : $avatarUrl" size="xl" />
                            <div class="ui-media-body">
                                <div class="ui-inline">
                                    <label class="ui-btn ui-btn--secondary ui-btn--sm">
                                        <x-ui.icon name="upload" /> {{ __('Elegir foto') }}
                                        <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="ui-sr">
                                    </label>
                                    @if ($avatar)
                                        <button type="button" wire:click="saveAvatar" class="ui-btn ui-btn--primary ui-btn--sm"><x-ui.icon name="check" /> {{ __('Guardar foto') }}</button>
                                    @endif
                                    @if ($avatarUrl)
                                        <button type="button" wire:click="removeAvatar" class="ui-btn ui-btn--ghost ui-btn--sm"><x-ui.icon name="trash" /> {{ __('Quitar') }}</button>
                                    @endif
                                    <span wire:loading wire:target="avatar" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                                </div>
                                @error('avatar') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                                <p class="ui-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB. Sin foto se usa un avatar por defecto.') }}</p>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Datos de la persona --}}
            <section class="ui-card" aria-labelledby="uf-data-title">
                <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="uf-data-title">{{ __('Datos personales') }}</h2></div>
                <div class="ui-card-p">
                    <div class="ui-form-grid">
                        <x-ui.field class="ui-span-full" :label="__('Nombre')" for="uf-name" :error="$errors->first('name')">
                            <input id="uf-name" type="text" wire:model="name" maxlength="120" placeholder="{{ __('Nombre y apellidos') }}" autocomplete="off"
                                @if ($errors->has('name')) aria-describedby="uf-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                        </x-ui.field>

                        @if ($editing)
                            <x-ui.field class="ui-span-full" :label="__('Correo (usuario de acceso)')" for="uf-email" :help="__('El correo es la identidad de login y no se puede cambiar tras crear el usuario.')">
                                <input id="uf-email" type="email" value="{{ $email }}" disabled class="ui-input" aria-describedby="uf-email-help">
                            </x-ui.field>
                        @else
                            <x-ui.field class="ui-span-full" :label="__('Correo (usuario de acceso)')" for="uf-email" :error="$errors->first('email')">
                                <input id="uf-email" type="email" wire:model="email" maxlength="190" placeholder="persona@mcaschool.education" autocomplete="off"
                                    @if ($errors->has('email')) aria-describedby="uf-email-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('email')])>
                            </x-ui.field>
                        @endif

                        <x-ui.field :label="__('Número de identidad')" :hint="__('dato sensible (cifrado)')" for="uf-nid" :error="$errors->first('nationalId')"
                            :help="__('Se guarda cifrado. Solo el Admin lo ve completo aquí; en el resto del sistema aparece enmascarado.')">
                            <input id="uf-nid" type="text" wire:model="nationalId" maxlength="40" autocomplete="off" placeholder="{{ __('Documento de identidad') }}"
                                aria-describedby="{{ $errors->has('nationalId') ? 'uf-nid-error ' : '' }}uf-nid-help" @class(['ui-input', 'is-invalid' => $errors->has('nationalId')])>
                        </x-ui.field>

                        <x-ui.field :label="__('Departamento')" for="uf-dep" :error="$errors->first('department')">
                            <select id="uf-dep" wire:model="department" @if ($errors->has('department')) aria-describedby="uf-dep-error" @endif @class(['ui-select', 'is-invalid' => $errors->has('department')])>
                                <option value="">— {{ __('Sin asignar') }} —</option>
                                @foreach ($departments as $dep)
                                    <option value="{{ $dep }}">{{ $dep }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    </div>
                </div>
            </section>

            {{-- Rol, estado y permisos --}}
            <section class="ui-card" aria-labelledby="uf-access-title">
                <div class="ui-card-h">
                    <x-ui.icon name="shield" class="ui-card-ic" /><h2 id="uf-access-title">{{ __('Rol y acceso') }}</h2>
                    @if ($editing)
                        <span class="sp"></span>
                        <x-ui.button size="sm" icon="refresh" wire:click="regenerateInvitation">{{ __('Generar enlace de acceso') }}</x-ui.button>
                    @endif
                </div>
                <div class="ui-card-p ui-stack">
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Rol')" for="uf-role" :error="$errors->first('role')">
                            <select id="uf-role" wire:model="role" @if ($errors->has('role')) aria-describedby="uf-role-error" @endif @class(['ui-select', 'is-invalid' => $errors->has('role')])>
                                @foreach ($roles as $r)
                                    <option value="{{ $r->value }}">{{ __($r->label()) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <div class="ui-field">
                            <span class="ui-label" id="uf-status-label">{{ __('Estado') }}</span>
                            <div class="ui-checks--field ui-inline">
                                <div class="ui-seg" role="group" aria-labelledby="uf-status-label">
                                    <button type="button" wire:click="$set('status','active')" aria-pressed="{{ $status === 'active' ? 'true' : 'false' }}">{{ __('Activo') }}</button>
                                    <button type="button" wire:click="$set('status','inactive')" aria-pressed="{{ $status === 'inactive' ? 'true' : 'false' }}">{{ __('Inactivo') }}</button>
                                </div>
                            </div>
                            @error('status') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($canGrantSuperAdmin)
                        <label class="ui-check">
                            <input type="checkbox" wire:model="is_super_admin"> {{ __('Super-admin (ve todas las instituciones)') }}
                        </label>
                    @endif

                    <label class="ui-check ui-check--top">
                        <input type="checkbox" wire:model="canEmailCode">
                        <span class="ui-check-text">
                            <span class="ui-strong">{{ __('Modo código de correo (HTML/CSS)') }}</span>
                            <span class="ui-help">{{ __('Permite al usuario alternar el editor a código HTML con vista previa para diseñar correos y plantillas. El Administrador siempre lo tiene.') }}</span>
                        </span>
                    </label>
                </div>
            </section>

            <div class="ui-form-actions ui-form-actions--end">
                <x-ui.button variant="ghost" :href="route('users.index')">{{ __('Cancelar') }}</x-ui.button>
                <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="ui-btn ui-btn--primary">
                    <span wire:loading.remove wire:target="save" class="ui-btn-label"><x-ui.icon name="check" /> {{ $editing ? __('Guardar cambios') : __('Crear usuario') }}</span>
                    <span wire:loading wire:target="save"><span class="ui-btn-label"><span class="ui-spin"></span> {{ __('Guardando…') }}</span></span>
                </button>
            </div>
        </div>
    </div>
</div>
