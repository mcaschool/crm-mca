<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Audit\Services\AuditService;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, AuditService $audit): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        // Un usuario desactivado no entra, aunque las credenciales sean validas.
        if ($user === null || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Segundo factor: contraseña correcta pero falta el TOTP. NO se finaliza la
        // sesion: se cierra el login provisional, se guarda el usuario pendiente y se
        // pasa al desafio 2FA.
        if ($user->hasTwoFactorEnabled()) {
            $userId = $user->getKey();
            $remember = $request->boolean('remember');
            Auth::guard('web')->logout();
            $request->session()->put('login.2fa_user_id', $userId);
            $request->session()->put('login.2fa_remember', $remember);

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        // Login completado (sin 2FA). Contexto guest: se audita via logAuth (resuelve
        // institucion desde el usuario). El de usuarios con 2FA se registra al superar
        // el desafio, en TwoFactorChallengeController.
        $audit->logAuth('auth.login_success', $user->email);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        // Se captura el correo ANTES de cerrar la sesion (luego auth()->id() es null).
        $email = $request->user()?->email;

        Auth::guard('web')->logout();

        if ($email !== null) {
            $audit->logAuth('auth.logout', $email);
        }

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
