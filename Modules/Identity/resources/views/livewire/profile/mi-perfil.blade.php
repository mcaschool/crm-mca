<div class="py-10">
    <x-ui.styles />
    <div class="mca-panel max-w-2xl mx-auto sm:px-6 lg:px-8 px-4">
        <p class="mca-sub" style="margin:0 0 18px">Tus datos son gestionados por un administrador. Aquí solo puedes cambiar tu contraseña y tu foto.</p>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        @if (session('mustEnable2fa') && ! $user->hasTwoFactorEnabled())
            <div class="mca-toast err fade"><x-ui.icon name="lock" class="ic" /> La verificación en dos pasos es obligatoria. Actívala aquí abajo para poder usar el panel.</div>
        @endif

        {{-- Foto de perfil (editable por el propio usuario) --}}
        <div class="card card-p fade" style="margin-bottom:16px">
            <div style="display:flex;align-items:center;gap:16px">
                <span class="mca-av lg">
                    @if ($avatar)
                        <img src="{{ $avatar->temporaryUrl() }}" alt="preview">
                    @elseif ($user->avatarUrl())
                        <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}">
                    @else
                        <x-ui.icon name="user" />
                    @endif
                </span>
                <div>
                    <h3 class="mca-h1" style="font-size:16px;margin:0 0 3px">Foto de perfil</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                        <label class="mca-filebtn">
                            <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> Elegir foto
                            <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                        </label>
                        @if ($avatar)
                            <button type="button" wire:click="saveAvatar" class="btn btn-primary btn-sm">Guardar foto</button>
                        @endif
                        @if ($user->avatarUrl())
                            <button type="button" wire:click="removeAvatar" class="btn btn-ghost btn-sm">Quitar</button>
                        @endif
                        <span wire:loading wire:target="avatar" class="mca-help"><span class="mca-spin"></span> Cargando…</span>
                    </div>
                    @error('avatar') <span class="mca-err">{{ $message }}</span> @enderror
                    <div class="mca-help">PNG, JPG, SVG o WebP · máx 1 MB. Es tu imagen; puedes cambiarla cuando quieras.</div>
                </div>
            </div>
        </div>

        {{-- Datos (solo lectura) --}}
        <div class="card card-p fade">
            <h3 class="mca-h1" style="font-size:16px;margin-bottom:16px">Datos personales</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
                <div>
                    <div class="mca-lbl">Nombre</div>
                    <div style="font-weight:600;color:var(--ink)">{{ $user->name }}</div>
                </div>
                <div>
                    <div class="mca-lbl">Correo (usuario de acceso)</div>
                    <div style="font-weight:600;color:var(--ink)">{{ $user->email }}</div>
                </div>
                <div>
                    <div class="mca-lbl">Número de identidad</div>
                    <div style="font-weight:600;color:var(--ink);font-family:monospace">{{ $user->maskedNationalId() ?? '—' }}</div>
                </div>
                <div>
                    <div class="mca-lbl">Departamento</div>
                    <div>@if ($user->department)<span class="badge badge-soft">{{ $user->department }}</span>@else <span class="mca-muted">Sin asignar</span> @endif</div>
                </div>
                <div>
                    <div class="mca-lbl">Rol</div>
                    <div style="font-weight:600;color:var(--ink)">{{ $user->role->label() }}</div>
                </div>
            </div>
            <div class="mca-help" style="margin-top:14px">Si algún dato es incorrecto, contacta con un administrador. No puedes editarlos desde aquí.</div>
        </div>

        {{-- Cambiar contraseña --}}
        <div class="card card-p fade" style="margin-top:16px">
            <h3 class="mca-h1" style="font-size:16px;margin-bottom:4px">Cambiar contraseña</h3>
            <p class="mca-sub" style="margin-bottom:16px">Necesitas tu contraseña actual. La nueva debe cumplir los requisitos de seguridad.</p>

            <div class="field">
                <label>Contraseña actual</label>
                <input type="password" wire:model="current_password" autocomplete="current-password">
                @error('current_password') <span class="mca-err">{{ $message }}</span> @enderror
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:200px">
                    <label>Nueva contraseña</label>
                    <input type="password" wire:model="password" autocomplete="new-password">
                    @error('password') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field" style="flex:1;min-width:200px">
                    <label>Confirmar nueva contraseña</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password">
                </div>
            </div>
            <button type="button" wire:click="updatePassword" wire:loading.attr="disabled" wire:target="updatePassword" class="btn btn-primary" style="margin-top:6px">
                <span wire:loading.remove wire:target="updatePassword"><x-ui.icon name="check" class="ic" style="width:16px;height:16px" /> Actualizar contraseña</span>
                <span wire:loading wire:target="updatePassword"><span class="mca-spin"></span> Guardando…</span>
            </button>
        </div>

        {{-- Verificación en dos pasos (2FA TOTP) --}}
        <div class="card card-p fade" style="margin-top:16px">
            <h3 class="mca-h1" style="font-size:16px;margin-bottom:4px"><x-ui.icon name="lock" class="ic" style="width:17px;height:17px;vertical-align:-3px" /> Verificación en dos pasos</h3>
            <p class="mca-sub" style="margin-bottom:16px">Obligatoria para acceder al panel. Usa una app como Google Authenticator, Authy o Microsoft Authenticator.</p>

            @if ($user->hasTwoFactorEnabled())
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <span class="badge badge-on"><x-ui.icon name="check" class="ic" style="width:13px;height:13px" /> Activa</span>
                    <button type="button" wire:click="regenerateRecoveryCodes" class="btn btn-ghost btn-sm"><x-ui.icon name="refresh" class="ic" style="width:14px;height:14px" /> Regenerar códigos de recuperación</button>
                    <button type="button" wire:click="disableTwoFactor" wire:confirm="¿Seguro que quieres desactivar la verificación en dos pasos?" class="btn btn-soft btn-sm">Desactivar</button>
                </div>
            @elseif ($user->two_factor_secret !== null)
                <p class="mca-help" style="margin-top:0;margin-bottom:12px">1) Escanea el código con tu app. 2) Introduce el código de 6 dígitos para confirmar.</p>
                <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
                    <div style="width:210px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:8px;line-height:0">{!! $twoFactorQr !!}</div>
                    <div style="flex:1;min-width:220px">
                        <div class="field">
                            <label>Código de verificación</label>
                            <input type="text" wire:model="confirmCode" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code">
                            @error('confirmCode') <span class="mca-err">{{ $message }}</span> @enderror
                        </div>
                        <button type="button" wire:click="confirmTwoFactor" class="btn btn-primary btn-sm">Confirmar y activar</button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="enableTwoFactor" class="btn btn-primary btn-sm"><x-ui.icon name="lock" class="ic" style="width:14px;height:14px" /> Activar 2FA</button>
            @endif

            @if ($recoveryCodesShown !== [])
                <div style="margin-top:16px;background:#FFFDF3;border:1px solid #F3E7BF;border-radius:10px;padding:12px 14px">
                    <div style="font-weight:700;color:var(--ink);margin-bottom:4px">Códigos de recuperación</div>
                    <p class="mca-help" style="margin-top:0">Guárdalos en un lugar seguro. Cada uno sirve UNA sola vez si pierdes el teléfono. No se volverán a mostrar.</p>
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;font-family:monospace;font-size:13.5px;margin:10px 0">
                        @foreach ($recoveryCodesShown as $rc)<div>{{ $rc }}</div>@endforeach
                    </div>
                    <button type="button" wire:click="dismissRecoveryCodes" class="btn btn-ghost btn-sm">Ya los he guardado</button>
                </div>
            @endif
        </div>
    </div>
</div>
