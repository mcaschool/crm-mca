<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Modules\Core\Http\Middleware\ResolveInstitutionFromBot;
use Modules\Core\Http\Middleware\ResolveInstitutionFromUser;
use Modules\Core\Http\Middleware\SetLocale;
use Modules\Core\Tenancy\CurrentInstitution;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Core';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'core';

    /**
     * Registra los servicios del cimiento.
     */
    public function register(): void
    {
        parent::register();

        // Contexto de institucion: un unico valor por peticion (Capa 1).
        $this->app->singleton(CurrentInstitution::class);
    }

    /**
     * Arranque del modulo: alias de middleware del andamiaje.
     */
    public function boot(): void
    {
        parent::boot();

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('institution.user', ResolveInstitutionFromUser::class);
        $router->aliasMiddleware('institution.bot', ResolveInstitutionFromBot::class);
        $router->aliasMiddleware('setlocale', SetLocale::class);
    }

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
