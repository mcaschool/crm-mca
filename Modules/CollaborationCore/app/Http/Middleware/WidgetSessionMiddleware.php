<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\StartSession;

final class WidgetSessionMiddleware extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (str_starts_with((string) $request->header('Authorization'), 'MCAWidget ')) {
            $session = new \Illuminate\Session\Store('widget', new \Illuminate\Session\ArraySessionHandler(1));
            $session->start();
            $request->setLaravelSession($session);

            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
