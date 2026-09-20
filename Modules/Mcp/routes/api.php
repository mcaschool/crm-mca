<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Http\Controllers\McpController;
use Modules\Mcp\Http\Middleware\VerifyMcpToken;

/*
|--------------------------------------------------------------------------
| Servidor MCP privado — POST /api/mcp
|--------------------------------------------------------------------------
| Streamable HTTP en modo JSON puro (sin SSE): cada request JSON-RPC es un
| POST independiente — apto para Hostinger (sin daemons). SIEMPRE detrás de
| VerifyMcpToken: no existe variante pública. GET/DELETE responden 405 (la
| spec permite no ofrecer stream de servidor).
*/

Route::post('mcp', [McpController::class, 'handle'])
    ->middleware(VerifyMcpToken::class)
    ->name('mcp.handle');

Route::match(['get', 'delete'], 'mcp', fn () => response()->json(
    ['error' => 'Método no soportado: este servidor MCP opera solo con POST (JSON-RPC).'],
    405,
))->middleware(VerifyMcpToken::class)->name('mcp.reject');
