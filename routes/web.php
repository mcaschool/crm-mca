<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Modules\Ai\Livewire\Advisor\Configure as AdvisorConfigure;
use Modules\Ai\Livewire\Advisor\Form as AdvisorForm;
use Modules\Ai\Livewire\Advisor\Index as AdvisorIndex;
use Modules\Ai\Livewire\Knowledge\Index as KnowledgeIndex;
use Modules\Catalog\Livewire\Categories\Manage as CategoriesManage;
use Modules\Catalog\Livewire\Programs\Form as ProgramsForm;
use Modules\Catalog\Livewire\Programs\Index as ProgramsIndex;
use Modules\Crm\Livewire\Contacts\Index as ContactsIndex;
use Modules\Crm\Livewire\Contacts\Show as ContactsShow;
use Modules\Crm\Livewire\Conversations\Show as ConversationsShow;
use Modules\Crm\Livewire\Leads\Create as LeadsCreate;
use Modules\Crm\Livewire\Leads\Index as LeadsIndex;
use Modules\Crm\Livewire\Leads\Show as LeadsShow;
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

/*
| Landing de DEMO del widget (solo desarrollo): embebe el widget de Celia con la
| public_key del bot para probarlo end-to-end. En produccion el widget se embebe
| en la web real de MCA; esta ruta es para verificacion local.
*/
Route::get('/widget-demo', function () {
    $bot = app(\Modules\Core\Tenancy\CurrentInstitution::class)
        ->runGlobally(fn () => \Modules\Institutions\Models\Bot::query()->where('status', 'active')->where('type', 'ia')->orderBy('id')->first());

    abort_if($bot === null, 404);

    return view('widget-demo', ['botKey' => $bot->public_key]);
});

$panel = Route::middleware(['auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class]);

if ($domain = config('crm.panel_domain')) {
    $panel->domain($domain);
}

$panel->group(function () {
    // Dashboard / inicio: pantalla de entrada adaptada por persona (Livewire).
    Route::get('/dashboard', \Modules\Crm\Livewire\Dashboard::class)->name('dashboard');

    // Mi perfil: datos en solo lectura + cambio de la propia contrasena (Livewire).
    Route::get('/mi-perfil', \Modules\Identity\Livewire\Profile\MiPerfil::class)->name('profile.me');
    // Perfil Breeze (se conserva la ruta por compatibilidad; ya no esta en el menu).
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

    // Asesores Inteligentes (lista + crear/editar). Lista visible a todos los roles
    // del panel; crear/editar es solo de Administrador (gating en el componente Form).
    Route::get('/advisors', AdvisorIndex::class)->name('advisors.index');
    Route::get('/advisors/create', AdvisorForm::class)->name('advisors.create');
    Route::get('/advisors/{bot}/edit', AdvisorForm::class)->name('advisors.edit');
    // Rutas previas (se conservan por compatibilidad; ya no estan en el menu).
    Route::get('/advisor', AdvisorConfigure::class)->name('ai.advisor.configure');
    Route::get('/knowledge', KnowledgeIndex::class)->name('ai.knowledge.index');

    // Catalogo de programas (Livewire, Admin y Marketing por Policy; Admisiones no).
    Route::get('/catalog', ProgramsIndex::class)->name('catalog.programs.index');
    Route::get('/catalog/create', ProgramsForm::class)->name('catalog.programs.create');
    Route::get('/catalog/{program}/edit', ProgramsForm::class)->name('catalog.programs.edit');
    Route::get('/catalog/categories', CategoriesManage::class)->name('catalog.categories');

    // CRM Core (Livewire; los tres roles trabajan los prospectos).
    Route::get('/crm/leads', LeadsIndex::class)->name('crm.leads.index');
    Route::get('/crm/leads/create', LeadsCreate::class)->name('crm.leads.create');
    Route::get('/crm/leads/{lead}', LeadsShow::class)->name('crm.leads.show');
    Route::get('/crm/contacts', ContactsIndex::class)->name('crm.contacts.index');
    Route::get('/crm/contacts/{contact}', ContactsShow::class)->name('crm.contacts.show');
    Route::get('/crm/conversations/{conversation}', ConversationsShow::class)->name('crm.conversations.show');

    // Auditoria de seguridad (Livewire, SOLO LECTURA, solo Admin por Policy en mount).
    Route::get('/audit', \Modules\Audit\Livewire\Logs\Index::class)->name('audit.index');

    // Conmutador de idioma del panel (persiste la preferencia del usuario).
    Route::post('/locale', \App\Http\Controllers\LocaleController::class)->name('locale.switch');

    // Ajustes generales del panel (marca/logo institucional). Solo Administrador.
    Route::get('/ajustes', \Modules\Institutions\Livewire\Settings::class)->name('settings.index');

    // Remitentes de correo saliente (Ajustes). Solo Administrador (gating en el componente).
    Route::get('/ajustes/remitentes', \Modules\Notifications\Livewire\EmailSenders\Manage::class)->name('settings.email-senders');

    // Plantillas de correo COMPARTIDAS (Ajustes). Solo Administrador (gating en el componente).
    Route::get('/ajustes/plantillas', \Modules\Notifications\Livewire\EmailTemplates\Manage::class)
        ->defaults('scope', 'shared')->name('settings.email-templates');

    // Plantillas de correo PROPIAS (privadas del usuario). Cualquiera que pueda enviar correo.
    Route::get('/mis-plantillas', \Modules\Notifications\Livewire\EmailTemplates\Manage::class)
        ->defaults('scope', 'mine')->name('email-templates.mine');

    // Cambiador de institucion activa (solo super-admin; barandilla en el controlador).
    Route::post('/institution/switch', InstitutionSwitchController::class)->name('institution.switch');
});

require __DIR__.'/auth.php';
