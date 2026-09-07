<?php

declare(strict_types=1);

namespace Modules\Social\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Registra las rutas API del módulo Social (webhook directo Meta). El panel (bandeja)
 * vive en routes/web.php de la app; aquí solo se mapean las rutas api stateless,
 * bajo el prefijo global /api, sin CSRF ni sesión.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Social';

    public function map(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->name('api.')
            ->group(module_path($this->name, '/routes/api.php'));
    }
}
