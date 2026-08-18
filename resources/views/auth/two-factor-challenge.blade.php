<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Verificación en dos pasos') }}
    </div>
    <p class="mb-4 text-sm text-gray-500">
        {{ __('Introduce el código de 6 dígitos de tu app de autenticación para continuar.') }}
    </p>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf

        <div>
            <x-input-label for="code" :value="__('Código de verificación')" />
            <x-text-input id="code" class="block mt-1 w-full tracking-widest text-center"
                          type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                          maxlength="6" placeholder="000000" autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <details class="mt-4">
            <summary class="text-sm text-gray-600 hover:text-gray-900 cursor-pointer">
                {{ __('¿No tienes tu teléfono? Usa un código de recuperación') }}
            </summary>
            <div class="mt-3">
                <x-input-label for="recovery_code" :value="__('Código de recuperación')" />
                <x-text-input id="recovery_code" class="block mt-1 w-full" type="text"
                              name="recovery_code" autocomplete="off" placeholder="XXXXX-XXXXX" />
            </div>
        </details>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button>{{ __('Verificar') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
