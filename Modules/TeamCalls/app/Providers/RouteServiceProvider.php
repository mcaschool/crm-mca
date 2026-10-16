<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;
use Modules\TeamCalls\Http\Middleware\EnsureCallsUiEnabled;
use Modules\TeamCalls\Http\Middleware\RequireCollaborationActor;
use Modules\TeamCalls\Http\Middleware\RespondWithCallsJson;

/**
 * Rutas de llamadas, sin sistema de autenticación propio:
 *  - API definitiva /api/v1/collaboration/calls con la pila de CollaborationCore (como TeamChat).
 *  - API web y página heredadas (las usa la interfaz actual): misma sesión, 2FA e institución, y el
 *    contexto autenticado de Core (RequireCollaborationActor: collaboration.use y dispositivo de Core)
 *    en lugar del permiso del panel del CRM. RespondWithCallsJson va por fuera para que toda respuesta,
 *    incluidas las de auth, CSRF y 2FA, salga en el formato JSON de esa API.
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

        Route::prefix('api/v1/collaboration/calls')->name('collaboration.calls.')
            ->middleware(['web', 'auth', CollaborationRequest::class])
            ->group(module_path($this->name, '/routes/collaboration.php'));

        $panel = Route::middleware([RespondWithCallsJson::class, 'web', 'auth', 'institution.user', 'setlocale', \App\Http\Middleware\EnsureTwoFactorEnabled::class, RequireCollaborationActor::class]);
        if ($domain = config('crm.panel_domain')) {
            $panel->domain($domain);
        }
        $panel->group(module_path($this->name, '/routes/web.php'));

        // Interfaz web (fase 3C, SOLO desarrollo): lecturas JSON con el mismo formato que la API, y la
        // página con la pila HTML del panel (auth redirige al login y el 2FA a «Mi perfil», como el resto).
        $uiJson = Route::middleware([RespondWithCallsJson::class, 'web', 'auth', 'institution.user', 'setlocale', \App\Http\Middleware\EnsureTwoFactorEnabled::class, EnsureCallsUiEnabled::class, RequireCollaborationActor::class]);
        $uiPage = Route::middleware(['web', 'auth', 'institution.user', 'setlocale', \App\Http\Middleware\EnsureTwoFactorEnabled::class, EnsureCallsUiEnabled::class, RequireCollaborationActor::class.':page']);
        if ($domain = config('crm.panel_domain')) {
            $uiJson->domain($domain);
            $uiPage->domain($domain);
        }
        $uiJson->group(module_path($this->name, '/routes/ui-api.php'));
        $uiPage->group(module_path($this->name, '/routes/ui.php'));
    }
}
