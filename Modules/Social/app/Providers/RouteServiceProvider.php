<?php

declare(strict_types=1);

namespace Modules\Social\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Registra las rutas del módulo Social:
 *  - API stateless (webhook directo Meta), bajo el prefijo global /api, sin CSRF ni sesión.
 *  - Web del panel (media privado de WhatsApp), con EXACTAMENTE la misma pila de middleware
 *    y dominio que el grupo del panel en routes/web.php de la app (las páginas Livewire de
 *    la bandeja siguen viviendo allí; aquí solo rutas propias del módulo).
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

        $panel = Route::middleware(['web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class]);
        if ($domain = config('crm.panel_domain')) {
            $panel->domain($domain);
        }
        $panel->group(module_path($this->name, '/routes/web.php'));
    }
}
