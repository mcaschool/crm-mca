<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Social\Http\Controllers\MediaController;

/*
|--------------------------------------------------------------------------
| Social — rutas web del panel (media privado de WhatsApp)
|--------------------------------------------------------------------------
| Cargadas por el RouteServiceProvider del módulo con la MISMA pila de
| middleware (y dominio) que el grupo del panel. Sirven los adjuntos de
| WhatsApp desde el disco PRIVADO: usuario autenticado + institución del
| propio scope del modelo + canWorkCrm (en el controlador).
*/

Route::get('/social/media/{message}/{index}', [MediaController::class, 'show'])
    ->whereNumber('message')
    ->whereNumber('index')
    ->name('social.media.show');
