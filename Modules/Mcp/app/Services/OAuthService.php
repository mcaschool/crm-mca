<?php

declare(strict_types=1);

namespace Modules\Mcp\Services;

use Illuminate\Support\Str;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Models\McpOauthAccessToken;
use Modules\Mcp\Models\McpOauthAuthCode;
use Modules\Mcp\Models\McpOauthClient;
use Modules\Mcp\Models\McpOauthRefreshToken;
use Modules\Mcp\Support\OAuthException;

/**
 * Núcleo OAuth 2.1 del servidor MCP: DCR, Authorization Code + PKCE S256, access/refresh
 * opacos (SHA-256 en BD, rotación de refresh, revocación, expiración, resource RFC 8707).
 * NO decide permisos del CRM: solo emite/valida credenciales y resuelve al mcp_client, que
 * es la única fuente de autorización operativa (profile/allow_write/institution).
 */
final class OAuthService
{
    public function issuer(): string
    {
        return rtrim((string) (config('mcp.oauth.issuer') ?: config('app.url')), '/');
    }

    public function resource(): string
    {
        return (string) (config('mcp.oauth.resource') ?: $this->issuer().'/api/mcp');
    }

    /** @return array<int,string> */
    public function scopesSupported(): array
    {
        return (array) config('mcp.oauth.scopes_supported', ['mcp:read', 'mcp:write', 'offline_access']);
    }

    // ------------------------------------------------------------- metadata

    /** @return array<string,mixed> */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => $this->scopesSupported(),
            'bearer_methods_supported' => ['header'],
        ];
    }

    /** @return array<string,mixed> */
    public function authorizationServerMetadata(): array
    {
        $base = $this->issuer();

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token',
            'registration_endpoint' => $base.'/oauth/register',
            'revocation_endpoint' => $base.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => $this->scopesSupported(),
            // CIMD NO implementado en esta fase: no se anuncia.
        ];
    }

    // ------------------------------------------------------------- DCR

    public function validateRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return false; // solo HTTPS con host
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        foreach ((array) config('mcp.oauth.allowed_redirect_hosts', []) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra un cliente público. Registrarse NO otorga acceso al CRM.
     *
     * @param  array<int,string>  $redirectUris
     */
    public function registerClient(array $redirectUris, ?string $name, ?string $scope): McpOauthClient
    {
        $redirectUris = array_values(array_unique(array_filter(array_map('strval', $redirectUris))));
        if ($redirectUris === []) {
            throw new OAuthException('invalid_redirect_uri', 'Se requiere al menos un redirect_uri.');
        }
        foreach ($redirectUris as $uri) {
            if (! $this->validateRedirectUri($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'redirect_uri no permitido: solo HTTPS de hosts autorizados.');
            }
        }

        return McpOauthClient::query()->create([
            'client_id' => 'mcpc_'.Str::random(40),
            'client_name' => $name !== null ? mb_substr($name, 0, 150) : null,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
            'scope' => $scope !== null ? mb_substr($scope, 0, 255) : null,
        ]);
    }

    // ------------------------------------------------------------- scopes

    /**
     * Scopes CONCEDIBLES según el estado del mcp_client. mcp:read siempre (lectura);
     * offline_access si se pidió (para refresh); mcp:write SOLO si el mcp_client puede
     * escribir (allow_write) — en esta fase no se concede.
     *
     * @param  array<int,string>  $requested
     * @return array<int,string>
     */
    public function grantableScopes(McpClient $mcpClient, array $requested): array
    {
        $granted = ['mcp:read'];
        if (in_array('offline_access', $requested, true)) {
            $granted[] = 'offline_access';
        }
        if (in_array('mcp:write', $requested, true) && $mcpClient->canWrite()) {
            $granted[] = 'mcp:write';
        }

        return array_values(array_unique($granted));
    }

    // ------------------------------------------------------------- authorization code

    /**
     * @param  array<int,string>  $scopes
     */
    public function issueAuthorizationCode(McpOauthClient $client, McpClient $mcpClient, int $userId, string $redirectUri, string $codeChallenge, array $scopes, string $resource): string
    {
        $code = 'mcpac_'.Str::random(48);
        McpOauthAuthCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->client_id,
            'mcp_client_id' => $mcpClient->id,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scope' => implode(' ', $scopes),
            'resource' => $resource,
            'expires_at' => now()->addSeconds((int) config('mcp.oauth.auth_code_ttl', 120)),
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * Intercambia el code por tokens (valida PKCE S256, un-solo-uso, binding y resource).
     *
     * @return array<string,mixed> respuesta del token endpoint
     */
    public function exchangeAuthorizationCode(string $clientId, string $code, string $codeVerifier, string $redirectUri, ?string $resource): array
    {
        $row = McpOauthAuthCode::query()->where('code_hash', hash('sha256', $code))->first();
        if ($row === null || $row->client_id !== $clientId) {
            throw new OAuthException('invalid_grant', 'Authorization code inválido.');
        }
        if ($row->used_at !== null) {
            // Reuso de un code ya canjeado: revoca todo lo emitido con él (defensa).
            $this->revokeByMcpClient($row->mcp_client_id);
            throw new OAuthException('invalid_grant', 'Authorization code ya utilizado.');
        }
        if ($row->expires_at->isPast()) {
            throw new OAuthException('invalid_grant', 'Authorization code expirado.');
        }
        if (! hash_equals($row->redirect_uri, $redirectUri)) {
            throw new OAuthException('invalid_grant', 'redirect_uri no coincide.');
        }
        if ($resource !== null && ! hash_equals($row->resource, $resource)) {
            throw new OAuthException('invalid_target', 'resource no coincide con la autorización.');
        }
        if (! $this->verifyPkce($codeVerifier, $row->code_challenge)) {
            throw new OAuthException('invalid_grant', 'PKCE no válido.');
        }

        $row->forceFill(['used_at' => now()])->save(); // un solo uso

        return $this->issueTokenPair($clientId, $row->mcp_client_id, $row->scope, $row->resource);
    }

    /**
     * grant_type=refresh_token: rota el refresh (revoca el anterior) y emite nuevo par.
     *
     * @return array<string,mixed>
     */
    public function refresh(string $clientId, string $refreshToken, ?string $resource): array
    {
        $row = McpOauthRefreshToken::query()->where('token_hash', hash('sha256', $refreshToken))->first();
        if ($row === null || $row->client_id !== $clientId) {
            throw new OAuthException('invalid_grant', 'Refresh token inválido.');
        }
        if ($row->used_at !== null || $row->rotated_to_id !== null) {
            // Reuso de un refresh ya rotado → posible fuga: revoca toda la cadena del cliente.
            $this->revokeByMcpClient($row->mcp_client_id);
            throw new OAuthException('invalid_grant', 'Refresh token ya utilizado (reuso detectado).');
        }
        if ($row->revoked_at !== null || $row->expires_at->isPast()) {
            throw new OAuthException('invalid_grant', 'Refresh token revocado o expirado.');
        }
        if ($resource !== null && ! hash_equals($row->resource, $resource)) {
            throw new OAuthException('invalid_target', 'resource no coincide.');
        }

        $pair = $this->issueTokenPair($clientId, $row->mcp_client_id, $row->scope, $row->resource);
        $row->forceFill(['used_at' => now(), 'rotated_to_id' => $pair['_refresh_id'] ?? null])->save();
        unset($pair['_refresh_id']);

        return $pair;
    }

    /**
     * Emite access (+ refresh si scope incluye offline_access). Devuelve la respuesta del
     * token endpoint; incluye _refresh_id (interno) para la rotación.
     *
     * @return array<string,mixed>
     */
    private function issueTokenPair(string $clientId, int $mcpClientId, string $scope, string $resource): array
    {
        $accessTtl = (int) config('mcp.oauth.access_token_ttl', 3600);
        $access = 'mcpoauth_'.Str::random(48);
        McpOauthAccessToken::query()->create([
            'token_hash' => hash('sha256', $access),
            'client_id' => $clientId,
            'mcp_client_id' => $mcpClientId,
            'scope' => $scope,
            'resource' => $resource,
            'expires_at' => now()->addSeconds($accessTtl),
            'created_at' => now(),
        ]);

        $response = [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'scope' => $scope,
        ];

        if (in_array('offline_access', array_filter(explode(' ', $scope)), true)) {
            $refresh = 'mcprt_'.Str::random(48);
            $refreshRow = McpOauthRefreshToken::query()->create([
                'token_hash' => hash('sha256', $refresh),
                'client_id' => $clientId,
                'mcp_client_id' => $mcpClientId,
                'scope' => $scope,
                'resource' => $resource,
                'expires_at' => now()->addSeconds((int) config('mcp.oauth.refresh_token_ttl', 2592000)),
                'created_at' => now(),
            ]);
            $response['refresh_token'] = $refresh;
            $response['_refresh_id'] = $refreshRow->id;
        }

        return $response;
    }

    // ------------------------------------------------------------- validación de acceso

    /** Devuelve el token de acceso USABLE (hash + activo + no expirado + resource), o null. */
    public function validateAccessToken(string $token): ?McpOauthAccessToken
    {
        $row = McpOauthAccessToken::query()->where('token_hash', hash('sha256', $token))->first();
        if ($row === null || ! $row->isUsable()) {
            return null;
        }
        if (! hash_equals($row->resource, $this->resource())) {
            return null; // audience distinta a este servidor
        }

        return $row;
    }

    public function revokeAccessToken(string $token): void
    {
        McpOauthAccessToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeRefreshToken(string $token): void
    {
        McpOauthRefreshToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** Revoca todos los tokens vivos de un mcp_client (defensa ante reuso). */
    public function revokeByMcpClient(int $mcpClientId): void
    {
        McpOauthAccessToken::query()->where('mcp_client_id', $mcpClientId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        McpOauthRefreshToken::query()->where('mcp_client_id', $mcpClientId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    // ------------------------------------------------------------- PKCE

    public function verifyPkce(string $verifier, string $challenge): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    public function findClient(string $clientId): ?McpOauthClient
    {
        return McpOauthClient::query()->where('client_id', $clientId)->first();
    }
}
