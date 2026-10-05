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
     * Script del widget: /widget/chat-widget.js (snippets actuales) y /widget/celia.js (nombre
     * antiguo, incrustado en webs en producción) → el mismo resources/widget/chat-widget.js,
     * con caché corta y revalidación. SIN middleware: es un recurso público (no abre sesión ni
     * pone cookies). Solo llega aquí porque ninguno de los dos existe como archivo en public/.
     */
    protected function mapLegacyWidgetScript(): void
    {
        Route::get('widget/chat-widget.js', \Modules\Chat\Http\Controllers\WidgetScriptController::class)
            ->name('widget.script');
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
