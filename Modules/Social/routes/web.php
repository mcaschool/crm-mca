<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Social\Http\Controllers\MediaController;
use Modules\Social\Http\Controllers\WhatsAppSignupController;
use Modules\Social\Livewire\WhatsAppTemplates;

/*
|--------------------------------------------------------------------------
| Social — rutas web del panel (módulo)
|--------------------------------------------------------------------------
| Cargadas por el RouteServiceProvider del módulo con la MISMA pila de
| middleware (y dominio) que el grupo del panel: adjuntos privados de
| WhatsApp, gestión de plantillas y el callback del Embedded Signup
| (protegido por feature flag + state de un solo uso).
*/

Route::get('/social/media/{message}/{index}', [MediaController::class, 'show'])
    ->whereNumber('message')
    ->whereNumber('index')
    ->name('social.media.show');

// Plantillas de WhatsApp (listado, diseñador, sync). Acceso Admin (policy en mount).
Route::get('/social/plantillas', WhatsAppTemplates::class)->name('social.wa-templates');

// Callback del Embedded Signup (Coexistence). CSRF del grupo web + flag + state.
Route::post('/social/whatsapp/conectar', [WhatsAppSignupController::class, 'store'])
    ->name('social.wa-signup');
