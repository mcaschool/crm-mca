<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCrmAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('profile.*', 'users.*', 'locale.*', 'institution.*')) {
            return $next($request);
        }
        if ($request->is('livewire/update')) {
            foreach ($request->input('components', []) as $component) {
                $snapshot = json_decode($component['snapshot'] ?? '', true);
                $name = $snapshot['memo']['name'] ?? '';
                if (str_starts_with($name, 'identity.profile.') || str_starts_with($name, 'identity.users.')) {
                    continue;
                }
                abort_unless($request->user()?->canWorkCrm(), 403);
            }

            return $next($request);
        }
        abort_unless($request->user()?->canWorkCrm(), 403);

        return $next($request);
    }
}
