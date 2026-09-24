<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Http\Controllers\OAuthController;

/*
|--------------------------------------------------------------------------
| OAuth 2.1 del servidor MCP (para ChatGPT) — en la RAÍZ del dominio
|--------------------------------------------------------------------------
| Metadata + DCR + /token + /revoke son públicos (JSON, sin CSRF, con throttle).
| /authorize exige sesión del panel + admin (consentimiento). NO afecta al Bearer
| de Claude Code ni al endpoint /api/mcp.
*/

// Pila del panel (misma que el resto del panel; el dominio se resuelve como la app).
$panel = ['web', 'auth', 'institution.user', 'setlocale', 'can:access-panel', \App\Http\Middleware\EnsureTwoFactorEnabled::class];

// --- Metadata (pública) ---
Route::get('.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource'])
    ->middleware('throttle:120,1')->name('mcp.oauth.prm');

// Variante con sufijo del path del recurso (algunos clientes MCP la piden).
Route::get('.well-known/oauth-protected-resource/api/mcp', [OAuthController::class, 'protectedResource'])
    ->middleware('throttle:120,1')->name('mcp.oauth.prm.path');

Route::get('.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer'])
    ->middleware('throttle:120,1')->name('mcp.oauth.asm');

// --- DCR (público, rate-limited) ---
Route::post('oauth/register', [OAuthController::class, 'register'])
    ->middleware('throttle:20,1')->name('mcp.oauth.register');

// --- Token + revocación (público, PKCE; sin CSRF) ---
Route::post('oauth/token', [OAuthController::class, 'token'])
    ->middleware('throttle:60,1')->name('mcp.oauth.token');

Route::post('oauth/revoke', [OAuthController::class, 'revoke'])
    ->middleware('throttle:60,1')->name('mcp.oauth.revoke');

// --- Authorize (sesión del panel + admin + consentimiento) ---
Route::get('oauth/authorize', [OAuthController::class, 'authorizeShow'])
    ->middleware($panel)->name('mcp.oauth.authorize');

Route::post('oauth/authorize', [OAuthController::class, 'authorizeApprove'])
    ->middleware($panel)->name('mcp.oauth.authorize.approve');
