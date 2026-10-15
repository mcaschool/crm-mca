<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\CallController;

/*
| API web de llamadas internas (JSON). Pila del panel aplicada en RouteServiceProvider: sesión,
| CSRF, auth, institución activa, permiso del panel y 2FA obligatorio. Límites por persona.
*/
Route::prefix('colaboracion/llamadas')->name('collab.calls.')->group(function () {
    Route::middleware('throttle:teamcalls-read')->group(function () {
        Route::get('/', [CallController::class, 'index'])->name('index');
        Route::get('/perdidas', [CallController::class, 'missed'])->name('missed');
        Route::get('/{call}', [CallController::class, 'show'])->name('show');
    });

    Route::post('/', [CallController::class, 'store'])->middleware('throttle:teamcalls-start')->name('store');

    Route::middleware('throttle:teamcalls-write')->group(function () {
        Route::post('/{call}/aceptar', [CallController::class, 'accept'])->name('accept');
        Route::post('/{call}/rechazar', [CallController::class, 'decline'])->name('decline');
        Route::post('/{call}/cancelar', [CallController::class, 'cancel'])->name('cancel');
        Route::post('/{call}/unirse', [CallController::class, 'join'])->name('join');
        Route::post('/{call}/salir', [CallController::class, 'leave'])->name('leave');
        Route::post('/{call}/finalizar', [CallController::class, 'end'])->name('end');
        Route::post('/{call}/invitar', [CallController::class, 'invite'])->name('invite');
        Route::post('/{call}/credenciales', [CallController::class, 'credentials'])->name('credentials');
    });
});
