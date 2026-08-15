<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\InstitutionSwitchController;
use Modules\Identity\Livewire\Users\Form as UsersForm;
use Modules\Identity\Livewire\Users\Index as UsersIndex;
use Modules\Integrations\Livewire\AiProcesses\Manage as AiProcessesManage;
use Modules\Integrations\Livewire\Integrations\Configure as IntegrationsConfigure;
use Modules\Integrations\Livewire\Integrations\Index as IntegrationsIndex;

/*
|--------------------------------------------------------------------------
| Panel administrativo
|--------------------------------------------------------------------------
| Todo el panel vive tras 'auth' + contexto de institucion (Capa 1) + idioma
| + la puerta 'access-panel'. En produccion se sirve en su subdominio (D7):
| si PANEL_DOMAIN esta definido, se aplica Route::domain(); en local/pruebas
| no hay restriccion de dominio.
*/

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

$panel = Route::middleware(['auth', 'institution.user', 'setlocale', 'can:access-panel']);

if ($domain = config('crm.panel_domain')) {
    $panel->domain($domain);
}

$panel->group(function () {
    // Dashboard (placeholder del cascaron; sin funcionalidad de otros modulos).
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // Perfil propio: cambio de datos y contrasena (Breeze).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Gestion de usuarios del panel (Livewire, gating por Policy en cada accion).
    Route::get('/users', UsersIndex::class)->name('users.index');
    Route::get('/users/create', UsersForm::class)->name('users.create');
    Route::get('/users/{user}/edit', UsersForm::class)->name('users.edit');

    // Integraciones y almacen de credenciales (Livewire, solo Admin por Policy).
    Route::get('/integrations', IntegrationsIndex::class)->name('integrations.index');
    Route::get('/integrations/{type}/configure', IntegrationsConfigure::class)->name('integrations.configure');
    Route::get('/ai-processes', AiProcessesManage::class)->name('integrations.ai-processes');

    // Cambiador de institucion activa (solo super-admin; barandilla en el controlador).
    Route::post('/institution/switch', InstitutionSwitchController::class)->name('institution.switch');
});

require __DIR__.'/auth.php';
