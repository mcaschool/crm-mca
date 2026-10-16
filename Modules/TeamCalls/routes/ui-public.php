<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\CallsUiController;

/*
| Lanzador de llamadas para otras aplicaciones de MCA Colaboración (fase 4E, solo desarrollo:
| EnsureCallsUiEnabled). Archivo estático sin secretos ni datos de la persona: no exige sesión porque
| el marco del widget universal puede no tenerla. Abrir o iniciar una llamada sigue pasando por la
| página y la API, con toda su pila (sesión, 2FA, institución, collaboration.use, CSRF).
*/
Route::get('colaboracion/teamcalls/lanzador.js', [CallsUiController::class, 'launcher'])
    ->name('collab.calls.ui.launcher')->middleware('throttle:120,1');
