<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header :title="__('Mi perfil')" :subtitle="__('Tus datos son gestionados por un administrador. Aquí solo puedes cambiar tu contraseña y tu foto.')" />

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        @if (session('mustEnable2fa') && ! $user->hasTwoFactorEnabled())
            <div class="ui-alert ui-tone--danger" role="alert"><x-ui.icon name="lock" /> {{ __('La verificación en dos pasos es obligatoria. Actívala aquí abajo para poder usar el panel.') }}</div>
        @endif

        <div class="ui-stack">
            {{-- Foto de perfil (editable por el propio usuario) --}}
            <section class="ui-card" aria-labelledby="me-photo-title">
                <div class="ui-card-h"><x-ui.icon name="image" class="ui-card-ic" /><h2 id="me-photo-title">{{ __('Foto de perfil') }}</h2></div>
                <div class="ui-card-p">
                    <div class="ui-media">
                        <x-ui.avatar :name="$user->name" :src="$avatar ? $avatar->temporaryUrl() : $user->avatarUrl()" size="xl" />
                        <div class="ui-media-body">
                            <div class="ui-inline">
                                <label class="ui-btn ui-btn--secondary ui-btn--sm">
                                    <x-ui.icon name="upload" /> {{ __('Elegir foto') }}
                                    <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="ui-sr">
                                </label>
                                @if ($avatar)
                                    <button type="button" wire:click="saveAvatar" class="ui-btn ui-btn--primary ui-btn--sm"><x-ui.icon name="check" /> {{ __('Guardar foto') }}</button>
                                @endif
                                @if ($user->avatarUrl())
                                    <button type="button" wire:click="removeAvatar" class="ui-btn ui-btn--ghost ui-btn--sm"><x-ui.icon name="trash" /> {{ __('Quitar') }}</button>
                                @endif
                                <span wire:loading wire:target="avatar" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                            </div>
                            @error('avatar') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            <p class="ui-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB. Es tu imagen; puedes cambiarla cuando quieras.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Datos (solo lectura) --}}
            <section class="ui-card" aria-labelledby="me-data-title">
                <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="me-data-title">{{ __('Datos personales') }}</h2></div>
                <div class="ui-card-p">
                    <dl class="ui-meta ui-meta--spaced ui-meta--stack-sm">
                        <dt>{{ __('Nombre') }}</dt>
                        <dd class="ui-strong">{{ $user->name }}</dd>
                        <dt>{{ __('Correo (usuario de acceso)') }}</dt>
                        <dd class="ui-strong">{{ $user->email }}</dd>
                        <dt>{{ __('Número de identidad') }}</dt>
                        <dd class="ui-mono">{{ $user->maskedNationalId() ?? '—' }}</dd>
                        <dt>{{ __('Departamento') }}</dt>
                        <dd>@if ($user->department)<x-ui.badge tone="blue">{{ $user->department }}</x-ui.badge>@else <span class="t-nil">{{ __('Sin asignar') }}</span> @endif</dd>
                        <dt>{{ __('Rol') }}</dt>
                        <dd class="ui-strong">{{ __($user->role->label()) }}</dd>
                    </dl>
                </div>
                <p class="ui-card-note">{{ __('Si algún dato es incorrecto, contacta con un administrador. No puedes editarlos desde aquí.') }}</p>
            </section>

            {{-- Cambiar contraseña --}}
            <section class="ui-card" aria-labelledby="me-pass-title">
                <div class="ui-card-h"><x-ui.icon name="lock" class="ui-card-ic" /><h2 id="me-pass-title">{{ __('Cambiar contraseña') }}</h2></div>
                <div class="ui-card-p ui-stack">
                    <p class="ui-help">{{ __('Escribe tu nueva contraseña y confírmala. Debe cumplir los requisitos de seguridad.') }}</p>
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Nueva contraseña')" for="me-pass" :error="$errors->first('password')">
                            <input id="me-pass" type="password" wire:model="password" autocomplete="new-password"
                                @if ($errors->has('password')) aria-describedby="me-pass-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('password')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Confirmar nueva contraseña')" for="me-pass2">
                            <input id="me-pass2" type="password" wire:model="password_confirmation" autocomplete="new-password" class="ui-input">
                        </x-ui.field>
                    </div>
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <button type="button" wire:click="updatePassword" wire:loading.attr="disabled" wire:target="updatePassword" class="ui-btn ui-btn--primary">
                        <span wire:loading.remove wire:target="updatePassword" class="ui-btn-label"><x-ui.icon name="check" /> {{ __('Actualizar contraseña') }}</span>
                        <span wire:loading wire:target="updatePassword"><span class="ui-btn-label"><span class="ui-spin"></span> {{ __('Guardando…') }}</span></span>
                    </button>
                </div>
            </section>

            {{-- Verificación en dos pasos (2FA TOTP) --}}
            <section class="ui-card" aria-labelledby="me-2fa-title">
                <div class="ui-card-h">
                    <x-ui.icon name="shield" class="ui-card-ic" /><h2 id="me-2fa-title">{{ __('Verificación en dos pasos') }}</h2>
                    @if ($user->hasTwoFactorEnabled())
                        <x-ui.badge tone="success" dot>{{ __('Activa') }}</x-ui.badge>
                    @endif
                </div>
                <div class="ui-card-p ui-stack">
                    <p class="ui-help">{{ __('Obligatoria para acceder al panel. Usa una app como Google Authenticator, Authy o Microsoft Authenticator.') }}</p>

                    @if ($user->hasTwoFactorEnabled())
                        <div class="ui-inline">
                            <x-ui.button size="sm" icon="refresh" wire:click="regenerateRecoveryCodes">{{ __('Regenerar códigos de recuperación') }}</x-ui.button>
                            <x-ui.button size="sm" variant="danger" wire:click="disableTwoFactor" wire:confirm="{{ __('¿Seguro que quieres desactivar la verificación en dos pasos?') }}">{{ __('Desactivar') }}</x-ui.button>
                        </div>
                    @elseif ($user->two_factor_secret !== null)
                        <p class="ui-help">{{ __('1) Escanea el código con tu app. 2) Introduce el código de 6 dígitos para confirmar.') }}</p>
                        <div class="ui-media ui-media--top">
                            <div class="ui-qr" role="img" aria-label="{{ __('Código QR para tu app de autenticación') }}">{!! $twoFactorQr !!}</div>
                            <div class="ui-media-body">
                                <x-ui.field :label="__('Código de verificación')" for="me-2fa-code" :error="$errors->first('confirmCode')">
                                    <input id="me-2fa-code" type="text" wire:model="confirmCode" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code"
                                        @if ($errors->has('confirmCode')) aria-describedby="me-2fa-code-error" @endif @class(['ui-input', 'ui-mono', 'is-invalid' => $errors->has('confirmCode')])>
                                </x-ui.field>
                                <div>
                                    <x-ui.button variant="primary" size="sm" icon="check" wire:click="confirmTwoFactor">{{ __('Confirmar y activar') }}</x-ui.button>
                                </div>
                            </div>
                        </div>
                    @else
                        <div>
                            <x-ui.button variant="primary" size="sm" icon="lock" wire:click="enableTwoFactor">{{ __('Activar 2FA') }}</x-ui.button>
                        </div>
                    @endif

                    @if ($recoveryCodesShown !== [])
                        <div class="ui-callout ui-tone--warning ui-callout--stack">
                            <b><x-ui.icon name="alert-triangle" /> {{ __('Códigos de recuperación') }}</b>
                            <span>{{ __('Guárdalos en un lugar seguro. Cada uno sirve UNA sola vez si pierdes el teléfono. No se volverán a mostrar.') }}</span>
                            <div class="ui-codes">
                                @foreach ($recoveryCodesShown as $rc)<div>{{ $rc }}</div>@endforeach
                            </div>
                            <div>
                                <x-ui.button size="sm" wire:click="dismissRecoveryCodes">{{ __('Ya los he guardado') }}</x-ui.button>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
