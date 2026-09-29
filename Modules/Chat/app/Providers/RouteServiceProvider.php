<?php

declare(strict_types=1);

namespace Modules\Chat\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Chat';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
        $this->mapLegacyWidgetScript();
    }

    /**
     * /widget/celia.js (nombre antiguo, incrustado en webs en producción) → mismo archivo que
     * public/widget/chat-widget.js. SIN middleware: es un recurso público (no abre sesión ni
     * pone cookies). Solo llega aquí porque celia.js ya no existe como archivo en public/.
     */
    protected function mapLegacyWidgetScript(): void
    {
        Route::get('widget/celia.js', \Modules\Chat\Http\Controllers\WidgetScriptController::class)
            ->name('widget.script.legacy');
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }
}
