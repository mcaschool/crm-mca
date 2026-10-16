<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;
use Modules\CollaborationRealtime\Http\RealtimeController;

Route::prefix('api/v1/collaboration')->middleware(['web', 'auth', CollaborationRequest::class, 'throttle:120,1'])->group(function (): void {
    Route::get('sync', [RealtimeController::class, 'sync'])->name('collaboration.sync');
    Route::get('realtime/signals', [RealtimeController::class, 'signals'])->name('collaboration.realtime.signals');
    Route::get('realtime/notifications', [RealtimeController::class, 'notifications'])->name('collaboration.realtime.notifications');
    Route::get('realtime/channel', [RealtimeController::class, 'channel'])->name('collaboration.realtime.channel');
    Route::post('realtime/auth', [RealtimeController::class, 'authorize'])->name('collaboration.realtime.auth');
});
