<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\Core\Tenancy\CurrentInstitution;
use Symfony\Component\HttpFoundation\Response;

final class CollaborationRequest
{
    public function __construct(private ActorContextFactory $factory, private CurrentInstitution $institution) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('collaboration.request_id', (string) Str::ulid());
        $this->institution->forget();
        try {
            $actor = $this->factory->fromAuthenticatedRequest($request);
            $request->attributes->set('collaboration.actor', $actor);

            return $this->institution->runFor($actor->institutionId, fn () => $next($request));
        } finally {
            $this->institution->forget();
        }
    }
}
