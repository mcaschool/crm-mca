<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Social\Http\Controllers\WebhookController;
use Modules\Social\Http\Middleware\VerifyMetaSignature;

/*
| Webhook DIRECTO Meta ↔ CRM (sin intermediario). Bajo el prefijo global /api, stateless,
| sin CSRF ni sesión. La "auth" no es de panel: es el verify_token (handshake GET) y la
| firma HMAC X-Hub-Signature-256 (POST).
|
|   GET  /api/social/webhook/{provider}  → handshake de verificación (hub.challenge)
|   POST /api/social/webhook/{provider}  → recepción de eventos (firma validada antes de procesar)
|
| {provider} ∈ whatsapp | instagram | messenger.
*/
Route::prefix('social/webhook')->group(function () {
    Route::get('{provider}', [WebhookController::class, 'verify'])
        ->where('provider', 'whatsapp|instagram|messenger')
        ->name('social.webhook.verify');

    Route::post('{provider}', [WebhookController::class, 'handle'])
        ->where('provider', 'whatsapp|instagram|messenger')
        ->middleware(VerifyMetaSignature::class)
        ->name('social.webhook.handle');
});
