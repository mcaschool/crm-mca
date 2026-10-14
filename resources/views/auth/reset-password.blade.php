<x-guest-layout>
    <div class="head">
        <h1>{{ __('Nueva contraseña') }}</h1>
        <p>{{ __('Elige una contraseña nueva para tu cuenta del CRM.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="field">
            <label for="email">{{ __('Correo electrónico') }}</label>
            <div class="input @error('email') has-error @enderror">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </div>
            @error('email') <span class="err" id="email-error" role="alert">{{ $message }}</span> @enderror
        </div>

        <div class="field">
            <label for="password">{{ __('Contraseña nueva') }}</label>
            <div class="input @error('password') has-error @enderror">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input id="password" type="password" name="password" required autocomplete="new-password"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            </div>
            @error('password') <span class="err" id="password-error" role="alert">{{ $message }}</span> @enderror
        </div>

        <div class="field">
            <label for="password_confirmation">{{ __('Confirma la contraseña') }}</label>
            <div class="input @error('password_confirmation') has-error @enderror">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                    @error('password_confirmation') aria-invalid="true" aria-describedby="password_confirmation-error" @enderror>
            </div>
            @error('password_confirmation') <span class="err" id="password_confirmation-error" role="alert">{{ $message }}</span> @enderror
        </div>

        <div class="actions">
            <button class="btn" type="submit">{{ __('Guardar contraseña') }}</button>
        </div>
    </form>

    <a href="{{ route('login') }}" class="back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        {{ __('Volver al acceso') }}
    </a>
</x-guest-layout>
