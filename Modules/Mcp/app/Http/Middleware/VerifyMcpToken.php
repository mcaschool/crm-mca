<?php

declare(strict_types=1);

namespace Modules\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Services\OAuthService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación del servidor MCP en DOS ramas:
 *  1) Bearer estático `mcp_...` (Claude Code): SHA-256 contra mcp_clients — SIN CAMBIOS.
 *  2) Si no coincide, access token OAuth `mcpoauth_...` (ChatGPT): validado por
 *     OAuthService (hash, activo, expiración, resource) y resuelto al mcp_client enlazado.
 *
 * El permiso operativo lo impone SIEMPRE el estado de mcp_client (profile/allow_write/
 * institution); el scope del token es una barrera adicional (se propaga a la request).
 * Sin token válido → 401 con WWW-Authenticate + resource_metadata (para que el cliente
 * inicie/renueve la autorización).
 */
final class VerifyMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '') {
            $this->debugAuthFail('no_token');

            return $this->unauthorized();
        }

        // Rama 1: Bearer estático de siempre (Claude Code). Intacta.
        $client = McpClient::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->first();

        if ($client !== null) {
            $this->touch($client);
            $request->attributes->set('mcp_client', $client);
            $request->attributes->set('mcp_oauth_scopes', null); // bearer: sin restricción por scope
            $this->debugAuth('bearer', $client);

            return $next($request);
        }

        // Rama 2: access token OAuth (ChatGPT).
        $access = app(OAuthService::class)->validateAccessToken($token);
        if ($access !== null) {
            $oauthClient = $access->mcpClient()->where('is_active', true)->first();
            if ($oauthClient !== null) {
                $this->touch($oauthClient);
                $request->attributes->set('mcp_client', $oauthClient);
                $request->attributes->set('mcp_oauth_scopes', $access->scopes());
                $this->debugAuth('oauth', $oauthClient);

                return $next($request);
            }
        }

        // Token presente pero inválido/expirado/revocado.
        $this->debugAuthFail('invalid_token');

        return $this->unauthorized('invalid_token', 'El token no es válido o ha expirado.');
    }

    /** Log diagnóstico TEMPORAL del resultado de autenticación (sin token ni secretos). */
    private function debugAuth(string $kind, McpClient $client): void
    {
        if (! config('mcp.debug_requests', false)) {
            return;
        }
        Log::info('mcp.request.auth', [
            'result' => 'ok',
            'auth' => $kind,
            'mcp_client_id' => $client->id,
            'profile' => $client->effectiveProfile(),
            'institution_id' => $client->institution_id,
            'allow_write' => (bool) $client->allow_write,
        ]);
    }

    private function debugAuthFail(string $reason): void
    {
        if (! config('mcp.debug_requests', false)) {
            return;
        }
        Log::info('mcp.request.auth', ['result' => 'unauthorized', 'reason' => $reason]);
    }

    private function touch(McpClient $client): void
    {
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->save();
        }
    }

    private function unauthorized(?string $error = null, string $description = ''): Response
    {
        $prm = app(OAuthService::class)->issuer().'/.well-known/oauth-protected-resource';
        $challenge = 'Bearer realm="mca-crm-mcp"';
        if ($error !== null) {
            $challenge .= ', error="'.$error.'"';
            if ($description !== '') {
                $challenge .= ', error_description="'.addslashes($description).'"';
            }
        }
        $challenge .= ', resource_metadata="'.$prm.'"';

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => 'No autorizado.'],
        ], 401, ['WWW-Authenticate' => $challenge]);
    }
}
