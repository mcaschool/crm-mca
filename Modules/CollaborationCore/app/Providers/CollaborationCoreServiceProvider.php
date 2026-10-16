<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Providers;

use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CollaborationCore\Contracts;
use Modules\CollaborationCore\Integration;
use Modules\CollaborationCore\Services;
use Modules\CollaborationCore\Storage;

final class CollaborationCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Services\PanelAccessSnapshot::class);
        foreach ([
            Contracts\ActorContextFactory::class => Services\ActorFactory::class,
            Contracts\AuthenticatedSessionContextFactory::class => Services\ContextFactory::class,
            Contracts\CollaborationAccess::class => Services\AccessService::class,
            Contracts\DeviceRegistry::class => Services\DatabaseDeviceRegistry::class,
            Contracts\UserDirectory::class => Services\InstitutionUserDirectory::class,
            Contracts\PresenceService::class => Services\InstitutionPresence::class,
            Contracts\NotificationDispatcher::class => Services\DatabaseNotificationDispatcher::class,
            Storage\PresenceStore::class => Storage\DatabasePresenceStore::class,
            Integration\CallPresenceAuthorizer::class => Integration\UnavailableCallPresenceAuthorizer::class,
        ] as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->commands([\Modules\CollaborationCore\Console\PruneCollaboration::class, \Modules\CollaborationCore\Console\WidgetApplicationsCommand::class]);
        Gate::define('collaboration.use', fn (User $user) => app(Services\ApplicationAccess::class)->allows($user, 'collaboration'));
        User::updated(function (User $user): void {
            app(Services\PanelAccessSnapshot::class)->forget();

        });
        \Modules\Institutions\Models\Institution::updated(function ($institution): void {
            app(Services\PanelAccessSnapshot::class)->forget();

        });
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User && request()->hasSession()) {
                app(Services\Revocation::class)->session((int) $event->user->id, hash('sha256', request()->session()->getId()), 'logout');
            }
        });
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/v1/collaboration*')) {
                return null;
            }
            $id = $request->attributes->get('collaboration.request_id') ?? (string) \Illuminate\Support\Str::ulid();
            if ($e instanceof \Modules\CollaborationCore\Exceptions\ContractException) {
                return response()->json($e->toResponse($id, $e->errorCode), $e->httpStatus);
            }
            $status = match (true) {
                $e instanceof \Illuminate\Validation\ValidationException, $e instanceof \InvalidArgumentException => 422,
                $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'unauthenticated',403,419 => 'forbidden',404 => 'not_found',422 => 'invalid',429 => 'too_many_requests',default => 'internal_error'
            };

            return response()->json(['error' => ['code' => $code, 'message' => $code, 'retryable' => $status >= 500, 'request_id' => $id]], $status === 419 ? 403 : $status);
        });
    }
}
