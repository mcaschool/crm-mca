<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Middleware\EnsureCallsUiEnabled;
use Modules\TeamCalls\Http\Middleware\RespondWithCallsJson;

/**
 * Rutas de la API web de llamadas, con EXACTAMENTE la pila de middleware y dominio del grupo del
 * panel en routes/web.php (sin sistema de autenticación propio). RespondWithCallsJson va por fuera
 * para que toda respuesta, incluidas las de auth, CSRF y 2FA, salga en el formato JSON de la API.
 * La API móvil (Sanctum) llegará con CollaborationCore.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'TeamCalls';

    public function map(): void
    {
        // Webhooks firmados del servidor de medios (stateless, bajo /api, sin CSRF ni sesión).
        Route::middleware('api')
            ->prefix('api')
            ->name('api.')
            ->group(module_path($this->name, '/routes/api.php'));

        $panel = Route::middleware([RespondWithCallsJson::class, 'web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class]);
        if ($domain = config('crm.panel_domain')) {
            $panel->domain($domain);
        }
        $panel->group(module_path($this->name, '/routes/web.php'));

        // Interfaz web (fase 3C, SOLO desarrollo): lecturas JSON con el mismo formato que la API, y la
        // página con la pila HTML del panel (auth redirige al login y el 2FA a «Mi perfil», como el resto).
        $uiJson = Route::middleware([RespondWithCallsJson::class, 'web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class, EnsureCallsUiEnabled::class]);
        $uiPage = Route::middleware(['web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class, EnsureCallsUiEnabled::class]);
        if ($domain = config('crm.panel_domain')) {
            $uiJson->domain($domain);
            $uiPage->domain($domain);
        }
        $uiJson->group(module_path($this->name, '/routes/ui-api.php'));
        $uiPage->group(module_path($this->name, '/routes/ui.php'));
    }
}
