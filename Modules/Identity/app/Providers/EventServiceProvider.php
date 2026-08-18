<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Identity\Listeners\LogFailedLogin;
use Modules\Identity\Listeners\LogLockout;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // Auditoria de seguridad del login (Sub-bloque 1 del endurecimiento).
        Failed::class => [LogFailedLogin::class],
        Lockout::class => [LogLockout::class],
    ];

    /**
     * Indicates if events should be discovered. Se desactiva: los listeners de
     * modulos se registran explicitamente en $listen (la deteccion automatica solo
     * escanea app/Listeners, no los modulos, y evitamos doble registro).
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
