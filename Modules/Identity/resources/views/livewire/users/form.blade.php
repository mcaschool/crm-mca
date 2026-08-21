<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('users.index') }}" class="btn btn-ghost btn-sm" title="Volver"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ $editing ? 'Editar usuario' : 'Crear usuario' }}</h1>
                    <p class="mca-sub">Perfil de un asesor humano del equipo.</p>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- Enlace de invitacion (acceso) --}}
        @if ($invitationLink)
            <div class="card card-p fade" style="border-color:#cfe0f5;background:#f6faff;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:8px;color:var(--mca);font-weight:700;margin-bottom:6px">
                    <x-ui.icon name="mail" class="ic" style="width:18px;height:18px" /> Enlace de acceso
                </div>
                <p class="mca-help" style="margin:0 0 10px">Comparte este enlace con la persona para que fije su contraseña. Caduca por seguridad; puedes generar otro. (En producción se enviaría por correo.)</p>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" readonly value="{{ $invitationLink }}" id="invlink"
                           style="flex:1;padding:9px 11px;border:1px solid var(--line);border-radius:10px;font-size:12px;background:#fff;color:var(--muted)">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('invlink').value);this.textContent='Copiado'">Copiar</button>
                </div>
            </div>
        @endif

        <div class="card card-p fade">
            @if ($editing)
                <div style="display:flex;align-items:center;gap:16px;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--line)">
                    <span class="mca-av lg">
                        @if ($avatar)
                            <img src="{{ $avatar->temporaryUrl() }}" alt="preview">
                        @elseif ($avatarUrl)
                            <img src="{{ $avatarUrl }}" alt="{{ $name }}">
                        @else
                            <x-ui.icon name="user" />
                        @endif
                    </span>
                    <div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <label class="mca-filebtn">
                                <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> Elegir foto
                                <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                            </label>
                            @if ($avatar)
                                <button type="button" wire:click="saveAvatar" class="btn btn-primary btn-sm">Guardar foto</button>
                            @endif
                            @if ($avatarUrl)
                                <button type="button" wire:click="removeAvatar" class="btn btn-ghost btn-sm">Quitar</button>
                            @endif
                            <span wire:loading wire:target="avatar" class="mca-help"><span class="mca-spin"></span> Cargando…</span>
                        </div>
                        @error('avatar') <span class="mca-err">{{ $message }}</span> @enderror
                        <div class="mca-help">PNG, JPG, SVG o WebP · máx 1 MB. Sin foto se usa un avatar por defecto.</div>
                    </div>
                </div>
            @endif

            <div class="field">
                <label>Nombre</label>
                <input type="text" wire:model="name" maxlength="120" placeholder="Nombre y apellidos">
                @error('name') <span class="mca-err">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label>Correo (usuario de acceso)</label>
                @if ($editing)
                    <input type="email" value="{{ $email }}" disabled style="background:var(--paper);color:var(--muted)">
                    <div class="mca-help">El correo es la identidad de login y no se puede cambiar tras crear el usuario.</div>
                @else
                    <input type="email" wire:model="email" maxlength="190" placeholder="persona@mcaschool.education">
                    @error('email') <span class="mca-err">{{ $message }}</span> @enderror
                @endif
            </div>

            <div class="field">
                <label>Número de identidad <span class="mca-muted" style="font-weight:400">· dato sensible (cifrado)</span></label>
                <input type="text" wire:model="nationalId" maxlength="40" autocomplete="off" placeholder="Documento de identidad">
                @error('nationalId') <span class="mca-err">{{ $message }}</span> @enderror
                <div class="mca-help">Se guarda cifrado. Solo el Admin lo ve completo aquí; en el resto del sistema aparece enmascarado.</div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:180px">
                    <label>Departamento</label>
                    <select wire:model="department">
                        <option value="">— Sin asignar —</option>
                        @foreach ($departments as $dep)
                            <option value="{{ $dep }}">{{ $dep }}</option>
                        @endforeach
                    </select>
                    @error('department') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:180px">
                    <label>Rol</label>
                    <select wire:model="role">
                        @foreach ($roles as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                    @error('role') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="field" style="margin-bottom:0">
                <label>Estado</label>
                <div class="mca-seg">
                    <button type="button" wire:click="$set('status','active')" class="{{ $status === 'active' ? 'active' : '' }}">Activo</button>
                    <button type="button" wire:click="$set('status','inactive')" class="{{ $status === 'inactive' ? 'active' : '' }}">Inactivo</button>
                </div>
                @error('status') <span class="mca-err">{{ $message }}</span> @enderror
            </div>

            @if ($canGrantSuperAdmin)
                <label class="chk" style="margin-top:14px;display:flex;gap:8px;align-items:center;font-size:13px;color:var(--ink)">
                    <input type="checkbox" wire:model="is_super_admin"> Super-admin (ve todas las instituciones)
                </label>
            @endif

            <label class="chk" style="margin-top:14px;display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--ink)">
                <input type="checkbox" wire:model="canEmailCode" style="margin-top:3px">
                <span>Modo código de correo (HTML/CSS)
                    <span class="mca-help" style="display:block;margin-top:2px">Permite al usuario alternar el editor a código HTML con vista previa para diseñar correos y plantillas. El Administrador siempre lo tiene.</span>
                </span>
            </label>
        </div>

        <div style="margin-top:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">
                <span wire:loading.remove wire:target="save"><x-ui.icon name="check" class="ic" style="width:16px;height:16px" /> {{ $editing ? 'Guardar cambios' : 'Crear usuario' }}</span>
                <span wire:loading wire:target="save"><span class="mca-spin"></span> Guardando…</span>
            </button>
            <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancelar</a>
            @if ($editing)
                <button type="button" wire:click="regenerateInvitation" class="btn btn-ghost" style="margin-left:auto">
                    <x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> Generar enlace de acceso
                </button>
            @endif
        </div>
    </div>
</div>
