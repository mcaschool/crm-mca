<?php

declare(strict_types=1);

namespace Modules\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Mcp\Models\McpClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación del servidor MCP: Bearer token emitido con `mcp:client`.
 * El token viaja SOLO en el header Authorization (nunca en la URL) y se
 * compara por su SHA-256 contra mcp_clients (revocable/rotable al instante:
 * is_active=false o rotate invalidan el acceso). Sin token válido → 401 y el
 * endpoint no revela nada más.
 */
final class VerifyMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '') {
            return $this->unauthorized();
        }

        $client = McpClient::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->first();
        if ($client === null) {
            return $this->unauthorized();
        }

        // last_used_at con trote de 60s para no escribir en cada llamada.
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('mcp_client', $client);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => 'No autorizado.'],
        ], 401, ['WWW-Authenticate' => 'Bearer realm="mca-crm-mcp"']);
    }
}
