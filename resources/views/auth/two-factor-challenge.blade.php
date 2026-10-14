<x-guest-layout>
    <div class="head">
        <h1>{{ __('Verificación en dos pasos') }}</h1>
        <p>{{ __('Introduce el código de 6 dígitos de tu app de autenticación para continuar.') }}</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf

        <div class="field">
            <label for="code">{{ __('Código de verificación') }}</label>
            <div class="input input--code @error('code') has-error @enderror">
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                    maxlength="6" placeholder="000000" autofocus
                    @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
            </div>
            @error('code') <span class="err" id="code-error" role="alert">{{ $message }}</span> @enderror
        </div>

        <details class="alt" @if ($errors->has('recovery_code') || old('recovery_code')) open @endif>
            <summary>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                {{ __('¿No tienes tu teléfono? Usa un código de recuperación') }}
            </summary>
            <div class="field">
                <label for="recovery_code">{{ __('Código de recuperación') }}</label>
                <div class="input @error('recovery_code') has-error @enderror">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/></svg>
                    <input id="recovery_code" type="text" name="recovery_code" autocomplete="off" placeholder="XXXXX-XXXXX"
                        @error('recovery_code') aria-invalid="true" aria-describedby="recovery_code-error" @enderror>
                </div>
                @error('recovery_code') <span class="err" id="recovery_code-error" role="alert">{{ $message }}</span> @enderror
            </div>
        </details>

        <div class="actions">
            <button class="btn" type="submit">{{ __('Verificar') }}</button>
        </div>
    </form>

    <div class="note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>{{ __('Acceso exclusivo para personal autorizado') }}</span>
    </div>
</x-guest-layout>
