<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\CurrentInstitution;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establece el contexto de institucion en las rutas del PANEL, a partir del
 * usuario autenticado. La institucion nunca se adivina ni llega del cliente.
 *
 * Nota multi-institucion (D1): un super-admin puede cambiar de institucion
 * activa; en ese caso el id activo se toma de la sesion, no del usuario. Ese
 * flujo de cambio pertenece al Bloque 1; aqui queda el gancho.
 */
final class ResolveInstitutionFromUser
{
    public function __construct(private readonly CurrentInstitution $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            // Super-admin con institucion activa elegida en sesion (gancho D1).
            $active = $request->session()->get('active_institution_id');

            if ($user->is_super_admin && $active !== null) {
                $institutionId = (int) $active;
            } else {
                $institutionId = $user->institution_id !== null ? (int) $user->institution_id : null;
            }

            $this->context->set($institutionId);
        }

        return $next($request);
    }
}
