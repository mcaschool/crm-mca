<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\TeamCalls\Http\CallLaunch;
use Symfony\Component\HttpFoundation\Response;

/**
 * La interfaz web de llamadas (fase 3C) existe solo en desarrollo y con TEAMCALLS_UI_ENABLED, hasta que
 * CollaborationCore aporte los permisos definitivos de colaboración. En cualquier otro caso: 404.
 */
final class EnsureCallsUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CallLaunch::enabled()) {
            abort(404);
        }

        return $next($request);
    }
}
