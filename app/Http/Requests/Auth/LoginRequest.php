<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Intentos fallidos permitidos por (correo + IP) antes de bloquear. */
    private const MAX_ATTEMPTS = 5;

    /** Escalado del bloqueo temporal (segundos): 1º 1min, 2º 5min, 3º 15min, 4º+ 1h. */
    private const LOCKOUT_LADDER = [60, 300, 900, 3600];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Login correcto: se resetea el contador y el escalado de bloqueo.
        RateLimiter::clear($this->throttleKey());
        Cache::forget($this->lockKey());
        Cache::forget($this->penaltyKey());
    }

    /**
     * Verifica que el login no este bloqueado. Bloqueo CRECIENTE: al superar los
     * intentos permitidos se bloquea temporalmente por (correo+IP); cada bloqueo
     * consecutivo dura mas (escala LOCKOUT_LADDER). El estado vive en cache.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        // 1) Bloqueo activo (tiene prioridad).
        $lockedUntil = Cache::get($this->lockKey());
        if (is_int($lockedUntil) && $lockedUntil > now()->getTimestamp()) {
            throw $this->throttleException($lockedUntil - now()->getTimestamp());
        }

        // 2) Se alcanzo el limite en la ventana -> nuevo bloqueo (escalado).
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $penalty = (int) Cache::get($this->penaltyKey(), 0) + 1;
        $seconds = self::LOCKOUT_LADDER[min($penalty, count(self::LOCKOUT_LADDER)) - 1];

        Cache::put($this->lockKey(), now()->getTimestamp() + $seconds, $seconds);
        Cache::put($this->penaltyKey(), $penalty, 6 * 3600); // recuerda el escalado 6h
        RateLimiter::clear($this->throttleKey());            // ventana limpia tras bloquear

        event(new Lockout($this)); // -> auditoria del bloqueo (LogLockout)

        throw $this->throttleException($seconds);
    }

    /**
     * Mensaje de bloqueo (generico: no revela si el correo existe).
     */
    private function throttleException(int $seconds): ValidationException
    {
        return ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Clave de rate limiting por (correo + IP).
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }

    private function lockKey(): string
    {
        return 'login:lock:'.$this->throttleKey();
    }

    private function penaltyKey(): string
    {
        return 'login:penalty:'.$this->throttleKey();
    }
}
