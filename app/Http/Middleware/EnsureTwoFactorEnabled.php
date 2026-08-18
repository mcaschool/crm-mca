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
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && ! $user->hasTwoFactorEnabled()
            && ! $request->routeIs('profile.me', 'logout', 'two-factor.*')) {
            return redirect()->route('profile.me')->with('mustEnable2fa', true);
        }

        return $next($request);
    }
}
