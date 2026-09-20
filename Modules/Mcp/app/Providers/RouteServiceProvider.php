<?php

declare(strict_types=1);

namespace Modules\Mcp\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Rutas API stateless del servidor MCP (sin CSRF ni sesión), bajo /api.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Mcp';

    public function map(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->name('api.')
            ->group(module_path($this->name, '/routes/api.php'));
    }
}
