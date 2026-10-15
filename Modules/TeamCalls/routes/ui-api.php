<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\CallsUiController;

/*
| Lecturas JSON que necesita la interfaz web (fase 3C, solo desarrollo), con el formato de la API de
| llamadas. El sondeo de «en-curso» es TEMPORAL: lo sustituirá CollaborationRealtime.
*/
Route::prefix('colaboracion/teamcalls')->name('collab.calls.ui.')->middleware('throttle:teamcalls-read')->group(function () {
    Route::get('/contactos', [CallsUiController::class, 'contacts'])->name('contacts');
    Route::get('/en-curso', [CallsUiController::class, 'live'])->name('live');
});
