<x-guest-layout>
    <div class="head">
        <h1>{{ __('Recuperar contraseña') }}</h1>
        <p>{{ __('Escribe el correo con el que accedes al CRM y te enviaremos un enlace para elegir una contraseña nueva.') }}</p>
    </div>

    @if (session('status'))
        <div class="alert alert-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="field">
            <label for="email">{{ __('Correo electrónico') }}</label>
            <div class="input @error('email') has-error @enderror">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="nombre@mcaschool.education"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </div>
            @error('email') <span class="err" id="email-error" role="alert">{{ $message }}</span> @enderror
        </div>

        <div class="actions">
            <button class="btn" type="submit">{{ __('Enviar enlace') }}</button>
        </div>
    </form>

    <a href="{{ route('login') }}" class="back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        {{ __('Volver al acceso') }}
    </a>
</x-guest-layout>
