<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Politica 2FA: OBLIGATORIO para TODOS los roles del panel. Un usuario autenticado
 * que aun no ha activado el segundo factor es empujado a "Mi perfil" para
 * configurarlo antes de usar el resto del panel. Se permiten las rutas necesarias
 * para poder activarlo (Mi perfil, logout). El endpoint de Livewire vive fuera de
 * este grupo, asi que las acciones de activacion/confirmacion pueden ejecutarse.
 *
 * Excepcion TEMPORAL y acotada (config auth.two_factor.exempt_emails): los correos
 * de esa lista pueden entrar SIN configurar el 2FA. Solo omite ESTE redirect; no
 * altera el desafio de login de quienes ya tienen 2FA activado. Lista vacia = 2FA
 * obligatorio para todos (comportamiento por defecto).
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && ! $user->hasTwoFactorEnabled()
            && ! $this->isExempt($user->email)
            && ! $request->routeIs('profile.me', 'logout', 'two-factor.*')) {
            return redirect()->route('profile.me')->with('mustEnable2fa', true);
        }

        return $next($request);
    }

    /**
     * ¿El correo está en la excepción temporal a la política 2FA? Comparación por
     * correo normalizado (trim + minúsculas) contra la lista ya normalizada de
     * config. No se registra ni expone en ningún sitio (ni logs ni auditoría).
     */
    private function isExempt(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        /** @var array<int, string> $exempt */
        $exempt = config('auth.two_factor.exempt_emails', []);

        return in_array(mb_strtolower(trim($email)), $exempt, true);
    }
}
