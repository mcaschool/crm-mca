<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;
use Modules\TeamChat\Http\WidgetController;

Route::get('/collaboration/widget/frame', [WidgetController::class, 'frame'])->middleware(['web', 'throttle:60,1']);
Route::get('/api/v1/collaboration/widget/config', [WidgetController::class, 'configuration'])->middleware(['web', 'throttle:60,1']);
Route::post('/api/v1/collaboration/widget/exchange', [WidgetController::class, 'exchange'])->middleware('throttle:30,1');
Route::middleware(['web', 'auth', CollaborationRequest::class])->group(function (): void {
    Route::get('/collaboration/widget/authorize', [WidgetController::class, 'authorizeForm'])->name('collaboration.widget.authorize');
    Route::post('/collaboration/widget/authorize', [WidgetController::class, 'authorize'])->middleware('throttle:30,1');
});
Route::prefix('api/v1/collaboration/widget')->middleware(['web', 'auth', CollaborationRequest::class, 'throttle:120,1'])->group(function (): void {
    Route::get('content', [WidgetController::class, 'content']);
    Route::get('state', [WidgetController::class, 'state'])->middleware('throttle:30,1');
    foreach (['heartbeat', 'claim', 'ack', 'renew', 'revoke'] as $action) {
        Route::post($action, [WidgetController::class, $action]);
    }
    Route::match(['GET', 'PUT'], 'preferences', [WidgetController::class, 'preferences']);
});
