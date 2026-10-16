<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;
use Modules\TeamChat\Http\ChatController;

Route::middleware(['web', 'auth', 'institution.user', 'setlocale', \App\Http\Middleware\EnsureTwoFactorEnabled::class, CollaborationRequest::class])
    ->get('/colaboracion', fn () => response()->view('teamchat::inbox')->header('Cache-Control', 'no-store'))->name('teamchat.index');

Route::prefix('api/v1/collaboration')->middleware(['web', 'auth', CollaborationRequest::class, 'throttle:120,1'])->group(function (): void {
    Route::post('spaces', [\Modules\TeamChat\Http\SpaceController::class, 'create'])->middleware('throttle:teamchat.commands');
    Route::get('teams', [\Modules\TeamChat\Http\SpaceController::class, 'teams']);
    Route::get('teams/{team}', [\Modules\TeamChat\Http\SpaceController::class, 'team'])->whereUlid('team');
    Route::get('teams/{team}/channels', [\Modules\TeamChat\Http\SpaceController::class, 'channels'])->whereUlid('team');
    Route::get('spaces/{scope}/{id}/members', [\Modules\TeamChat\Http\SpaceController::class, 'members'])->whereIn('scope', ['team', 'conversation'])->whereUlid('id');
    Route::get('spaces/{scope}/{id}/changes', [\Modules\TeamChat\Http\SpaceController::class, 'history'])->whereIn('scope', ['team', 'conversation'])->whereUlid('id');
    Route::put('spaces/{scope}/{id}', [\Modules\TeamChat\Http\SpaceController::class, 'update'])->whereIn('scope', ['team', 'conversation'])->whereUlid('id')->middleware('throttle:teamchat.commands');
    Route::post('spaces/{scope}/{id}/members', [\Modules\TeamChat\Http\SpaceController::class, 'member'])->whereIn('scope', ['team', 'conversation'])->whereUlid('id')->middleware('throttle:teamchat.commands');
    Route::get('teamchat/directory', [ChatController::class, 'directory']);
    Route::get('conversations', [ChatController::class, 'index']);
    Route::post('conversations/direct', [ChatController::class, 'direct'])->middleware('throttle:teamchat.commands');
    Route::get('conversations/{conversation}', [ChatController::class, 'show'])->whereUlid('conversation');
    Route::get('conversations/{conversation}/messages', [ChatController::class, 'messages'])->whereUlid('conversation');
    Route::post('conversations/{conversation}/messages', [ChatController::class, 'send'])->whereUlid('conversation')->middleware('throttle:teamchat.commands');
    Route::post('conversations/{conversation}/receipts', [ChatController::class, 'receipt'])->whereUlid('conversation');
    Route::put('conversations/{conversation}/membership', [ChatController::class, 'membership'])->whereUlid('conversation');
});
