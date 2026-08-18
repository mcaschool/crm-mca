<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Audit\Services\AuditService;
use Modules\Identity\Services\TwoFactorService;

/**
 * Segundo factor del login: tras usuario+contraseña, se pide el codigo TOTP de 6
 * digitos (o un codigo de recuperacion). El usuario pendiente vive en sesion, sin
 * autenticar, hasta superar el desafio. Tiene su propio throttle (evita fuerza
 * bruta del codigo de 6 digitos).
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.2fa_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $tfa, AuditService $audit): RedirectResponse
    {
        $userId = $request->session()->get('login.2fa_user_id');
        if ($userId === null) {
            return redirect()->route('login');
        }

        $key = '2fa:'.$userId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'code' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive() || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['login.2fa_user_id', 'login.2fa_remember']);

            return redirect()->route('login');
        }

        $code = (string) $request->input('code', '');
        $recovery = (string) $request->input('recovery_code', '');

        $ok = ($code !== '' && $tfa->verify((string) $user->two_factor_secret, $code))
            || ($recovery !== '' && $tfa->consumeRecoveryCode($user, $recovery));

        if (! $ok) {
            RateLimiter::hit($key, 300);
            $audit->logAuth('auth.2fa_failed', $user->email);

            throw ValidationException::withMessages([
                'code' => trans('auth.two_factor_failed'),
            ]);
        }

        // Superado: se finaliza la sesion como en un login normal.
        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.2fa_remember', false);
        $request->session()->forget('login.2fa_user_id');

        Auth::guard('web')->loginUsingId($userId, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // Login completado tras superar el segundo factor.
        $audit->logAuth('auth.login_success', $user->email, ['via' => '2fa']);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
