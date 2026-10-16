<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\WidgetSessions;

final class WidgetCsrf extends PreventRequestForgery
{
    public function handle($request, Closure $next)
    {
        if (! str_starts_with((string) $request->header('Authorization'), 'MCAWidget ')) {
            return parent::handle($request, $next);
        }
        $path = $request->path();
        if (! preg_match('#^api/v1/collaboration/(widget/(content|heartbeat|claim|ack|preferences|renew|revoke|state)|me|users/[0-9]+|presence(?:/heartbeat|/status)?|sync|realtime/(?:signals|notifications|channel|auth)|teamchat/directory|conversations(?:/.*)?|spaces(?:/.*)?|teams(?:/.*)?|uploads(?:/.*)?)$#D', $path)) {
            throw new ContractException('forbidden', 403);
        }
        $origin = $request->header('Origin');
        if ($origin && $origin !== rtrim((string) config('collaboration_widget.central_origin'), '/')) {
            throw new ContractException('forbidden', 403);
        }
        try {
            $binding = app(WidgetSessions::class)->authenticate(substr($request->header('Authorization'), 10), (string) $request->header('X-Widget-Instance'), (string) $request->header('X-Widget-App'), (string) $request->header('X-Widget-Origin'));
        } catch (ContractException $e) {
            if ($e->errorCode === 'forbidden') {
                throw new ContractException('session_revoked', 401);
            }
            throw $e;
        }
        $caps = json_decode($binding['application']->capabilities, true);
        if (! in_array('chat', $caps, true) || (str_contains($path, 'uploads') && ! in_array('media', $caps, true))
            || (in_array($path, ['api/v1/collaboration/widget/claim', 'api/v1/collaboration/widget/ack'], true) && ! in_array('notifications', $caps, true))) {
            throw new ContractException('forbidden', 403);
        }
        $request->attributes->set('widget.binding', $binding);
        $request->attributes->set('widget.actor', $binding['actor']);
        $user = \App\Models\User::query()->findOrFail($binding['actor']->userId);
        Auth::guard()->setUser($user);
        $request->setUserResolver(fn () => $user);

        // Only verified, memory-held credentials bypass cookie CSRF; ordinary sessions keep Laravel validation.
        return $next($request);
    }
}
