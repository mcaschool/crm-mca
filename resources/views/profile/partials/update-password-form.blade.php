<div class="ui-card-h"><x-ui.icon name="lock" class="ui-card-ic" /><h2 id="pf-pass-title">{{ __('Cambiar contraseña') }}</h2></div>

<form method="post" action="{{ route('password.update') }}">
    @csrf
    @method('put')

    <div class="ui-card-p ui-stack">
        <p class="ui-help">{{ __('Usa una contraseña larga y aleatoria para mantener tu cuenta segura.') }}</p>

        <div class="ui-stack ui-form-narrow">
            <x-ui.field :label="__('Contraseña actual')" for="update_password_current_password" :error="$errors->updatePassword->first('current_password')">
                <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password"
                    @if ($errors->updatePassword->has('current_password')) aria-describedby="update_password_current_password-error" @endif
                    @class(['ui-input', 'is-invalid' => $errors->updatePassword->has('current_password')])>
            </x-ui.field>

            <x-ui.field :label="__('Nueva contraseña')" for="update_password_password" :error="$errors->updatePassword->first('password')">
                <input id="update_password_password" name="password" type="password" autocomplete="new-password"
                    @if ($errors->updatePassword->has('password')) aria-describedby="update_password_password-error" @endif
                    @class(['ui-input', 'is-invalid' => $errors->updatePassword->has('password')])>
            </x-ui.field>

            <x-ui.field :label="__('Confirmar contraseña')" for="update_password_password_confirmation" :error="$errors->updatePassword->first('password_confirmation')">
                <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                    @if ($errors->updatePassword->has('password_confirmation')) aria-describedby="update_password_password_confirmation-error" @endif
                    @class(['ui-input', 'is-invalid' => $errors->updatePassword->has('password_confirmation')])>
            </x-ui.field>
        </div>
    </div>

    <div class="ui-card-f ui-card-f--end">
        @if (session('status') === 'password-updated')
            <p
                x-data="{ show: true }"
                x-show="show"
                x-transition
                x-init="setTimeout(() => show = false, 2000)"
                class="ui-help ui-text-success"
                role="status"
            >{{ __('Guardado.') }}</p>
        @endif
        <button type="submit" class="ui-btn ui-btn--primary"><x-ui.icon name="check" /> {{ __('Guardar') }}</button>
    </div>
</form>
