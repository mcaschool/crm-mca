<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\Controllers\LiveKitWebhookController;

/*
| Webhooks del servidor de medios (grupo «api»: sin sesión ni CSRF; los autentica su firma).
| Configurar en LiveKit: webhook.urls = https://<panel>/api/teamcalls/media/livekit
*/
Route::post('/teamcalls/media/livekit', LiveKitWebhookController::class)
    ->middleware('throttle:teamcalls-webhook')
    ->name('teamcalls.media.livekit');
