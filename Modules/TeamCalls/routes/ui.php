<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\CallsUiController;

/*
| Interfaz web de llamadas (fase 3C), solo desarrollo (EnsureCallsUiEnabled). Página y recursos con la
| pila del panel (HTML). Prefijo propio para no chocar con /colaboracion/llamadas/{call}.
*/
Route::prefix('colaboracion/teamcalls')->name('collab.calls.ui')->group(function () {
    Route::get('/', [CallsUiController::class, 'page'])->name('')->middleware('throttle:teamcalls-read');
    Route::get('/recursos/{asset}', [CallsUiController::class, 'asset'])->name('.asset')
        ->where('asset', '[a-z0-9.-]+');
});
