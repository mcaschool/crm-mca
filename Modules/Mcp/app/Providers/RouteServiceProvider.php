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

        // Pantalla de administración (panel). MISMA pila y dominio que el grupo del
        // panel en routes/web.php de la app, para no tocar ese archivo (que tiene
        // trabajo local ajeno). El acceso fino lo aplica el componente en mount().
        $panel = Route::middleware(['web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class]);
        if ($domain = config('crm.panel_domain')) {
            $panel->domain($domain);
        }
        $panel->group(module_path($this->name, '/routes/web.php'));
    }
}
