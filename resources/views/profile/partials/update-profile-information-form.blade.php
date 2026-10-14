<div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="pf-info-title">{{ __('Información del perfil') }}</h2></div>

<form method="post" action="{{ route('profile.update') }}">
    @csrf
    @method('patch')

    <div class="ui-card-p ui-stack">
        <p class="ui-help">{{ __('Actualiza el nombre y el correo electrónico de tu cuenta.') }}</p>

        <div class="ui-stack ui-form-narrow">
            <x-ui.field :label="__('Nombre')" for="name" :error="$errors->first('name')">
                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name"
                    @if ($errors->has('name')) aria-describedby="name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
            </x-ui.field>

            <x-ui.field :label="__('Correo electrónico')" for="email" :error="$errors->first('email')">
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('email')])>

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="ui-callout ui-callout--sm ui-tone--warning ui-callout--stack">
                        <span>{{ __('Tu correo electrónico no está verificado.') }}</span>
                        <button form="send-verification" class="ui-link ui-link--btn">
                            {{ __('Pulsa aquí para reenviar el correo de verificación.') }}
                        </button>

                        @if (session('status') === 'verification-link-sent')
                            <p class="ui-help ui-text-success">
                                {{ __('Se ha enviado un nuevo enlace de verificación a tu correo electrónico.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </x-ui.field>
        </div>
    </div>

    <div class="ui-card-f ui-card-f--end">
        @if (session('status') === 'profile-updated')
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
