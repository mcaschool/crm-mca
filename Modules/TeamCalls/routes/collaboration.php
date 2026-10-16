<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\CollaborationCallsController;

/*
| API definitiva de llamadas (MCA Colaboración 1.1), bajo /api/v1/collaboration/calls con la pila de
| CollaborationCore (la aplica RouteServiceProvider): web (sesión + CSRF), auth, CollaborationRequest
| (MFA, collaboration.use, dispositivo e institución) y límites propios de TeamCalls.
*/
Route::middleware('throttle:teamcalls-read')->group(function () {
    Route::get('/', [CollaborationCallsController::class, 'recent'])->name('index');
    Route::get('missed', [CollaborationCallsController::class, 'missed'])->name('missed');
    Route::get('active', [CollaborationCallsController::class, 'active'])->name('active');
    Route::get('{call}', [CollaborationCallsController::class, 'show'])->whereUlid('call')->name('show');
});
Route::post('/', [CollaborationCallsController::class, 'store'])->middleware('throttle:teamcalls-start')->name('store');
Route::middleware('throttle:teamcalls-write')->group(function () {
    Route::post('{call}/invite', [CollaborationCallsController::class, 'invite'])->whereUlid('call')->name('invite');
    Route::post('{call}/credentials', [CollaborationCallsController::class, 'credentials'])->whereUlid('call')->name('credentials');
    Route::post('{call}/{action}', [CollaborationCallsController::class, 'act'])->whereUlid('call')
        ->whereIn('action', ['accept', 'decline', 'cancel', 'join', 'leave', 'end'])->name('act');
});
