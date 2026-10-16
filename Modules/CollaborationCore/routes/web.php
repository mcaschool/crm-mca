<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Modules\CollaborationCore\Http\Controllers\CoreController;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;

// Existing cookie/session authentication and CSRF. No bearer/mobile login yet.
Route::prefix('api/v1/collaboration')->name('collaboration.')->middleware(['web', 'auth', CollaborationRequest::class, 'throttle:120,1'])->group(function (): void {
    Route::get('me', [CoreController::class, 'me'])->name('me');
    Route::get('users/{userId}', [CoreController::class, 'user'])->name('users.show');
    Route::get('devices', [CoreController::class, 'devices'])->name('devices.index');
    Route::delete('devices/{deviceId}', [CoreController::class, 'revoke'])->name('devices.revoke');
    Route::post('presence/heartbeat', [CoreController::class, 'heartbeat'])->name('presence.heartbeat');
    Route::put('presence/status', [CoreController::class, 'manual'])->name('presence.status');
    Route::get('presence', [CoreController::class, 'presence'])->name('presence.index');
    Route::match(['get', 'put'], 'notification-preferences', [CoreController::class, 'preferences'])->name('preferences');
    Route::put('identity-administrators/{userId}', [CoreController::class, 'identityAdministrator'])->name('identity.administrators');
    Route::put('applications/{application}/users/{userId}', [CoreController::class, 'grant'])->name('applications.grant');
});
