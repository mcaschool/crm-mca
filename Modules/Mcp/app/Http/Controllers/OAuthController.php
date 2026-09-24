<?php

declare(strict_types=1);

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Models\McpOauthClient;
use Modules\Mcp\Services\OAuthService;
use Modules\Mcp\Support\OAuthException;

/**
 * Capa OAuth 2.1 para ChatGPT sobre el MCP existente. Metadata (PRM + AS), DCR,
 * /authorize (con consentimiento de admin) y /token (Authorization Code + PKCE S256 y
 * refresh rotatorio). NO toca el Bearer de Claude Code ni las 20 tools.
 */
final class OAuthController
{
    public function __construct(private readonly OAuthService $oauth) {}

    // ------------------------------------------------------ metadata (público)

    public function protectedResource(): JsonResponse
    {
        return response()->json($this->oauth->protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json($this->oauth->authorizationServerMetadata());
    }

    // ------------------------------------------------------ DCR (público)

    public function register(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        $redirectUris = is_array($body['redirect_uris'] ?? null) ? $body['redirect_uris'] : [];

        // token_endpoint_auth_method: solo public client (none).
        $authMethod = (string) ($body['token_endpoint_auth_method'] ?? 'none');
        if ($authMethod !== 'none') {
            return $this->dcrError('invalid_client_metadata', 'Solo se admiten public clients (token_endpoint_auth_method=none).');
        }

        try {
            $client = $this->oauth->registerClient(
                $redirectUris,
                isset($body['client_name']) ? (string) $body['client_name'] : null,
                isset($body['scope']) ? (string) $body['scope'] : null,
            );
        } catch (OAuthException $e) {
            return $this->dcrError($e->error, $e->getMessage());
        }

        Log::info('mcp.oauth: DCR registro', ['client_id' => $client->client_id, 'redirect_uris' => $client->redirect_uris]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->timestamp,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => $client->scope ?? implode(' ', $this->oauth->scopesSupported()),
        ], 201);
    }

    // ------------------------------------------------------ /authorize (admin + consentimiento)

    public function authorizeShow(Request $request): View
    {
        $this->assertCanAuthorizeGlobal($request);
        [$client, $params] = $this->parseAuthorizeRequest($request);

        return view('mcp::oauth.authorize', [
            'app_name' => $client->client_name ?: 'ChatGPT',
            'scopes' => explode(' ', $params['scope']),
            'params' => $params,
        ]);
    }

    public function authorizeApprove(Request $request): RedirectResponse
    {
        $this->assertCanAuthorizeGlobal($request);
        [$client, $params] = $this->parseAuthorizeRequest($request);

        // Denegación explícita del admin.
        if ($request->input('decision') !== 'approve') {
            return redirect()->away($this->appendQuery($params['redirect_uri'], [
                'error' => 'access_denied', 'state' => $params['state'],
            ]));
        }

        // REAUTORIZACIÓN IDEMPOTENTE: si este OAuth client ya tiene un mcp_client operativo
        // ACTIVO, se reutiliza (no se crea uno nuevo por cada consentimiento). Si no existe o
        // fue revocado, se crea el contexto: Global (institution_id null), inspection, read-only.
        $mcpClient = $client->mcp_client_id !== null
            ? McpClient::query()->where('id', $client->mcp_client_id)->where('is_active', true)->first()
            : null;

        if ($mcpClient === null) {
            $mcpClient = McpClient::query()->create([
                'name' => 'ChatGPT (OAuth) · '.($client->client_name ?: $client->client_id),
                'assistant_type' => 'chatgpt',
                'profile' => McpClient::PROFILE_INSPECTION,
                'allow_write' => false,
                'auth_kind' => 'oauth',
                // Sin bearer estático utilizable: hash aleatorio inalcanzable por ningún token.
                'token_hash' => hash('sha256', 'oauth-no-bearer:'.Str::random(64)),
                'institution_id' => null,
                'is_active' => true,
            ]);
            $client->forceFill(['mcp_client_id' => $mcpClient->id])->save();
        }

        $granted = $this->oauth->grantableScopes($mcpClient, explode(' ', $params['scope']));
        $code = $this->oauth->issueAuthorizationCode(
            $client,
            $mcpClient,
            (int) $request->user()->id,
            $params['redirect_uri'],
            $params['code_challenge'],
            $granted,
            $params['resource'],
        );

        Log::info('mcp.oauth: autorización concedida', [
            'client_id' => $client->client_id, 'mcp_client_id' => $mcpClient->id, 'scope' => implode(' ', $granted),
        ]);

        return redirect()->away($this->appendQuery($params['redirect_uri'], [
            'code' => $code, 'state' => $params['state'],
        ]));
    }

    // ------------------------------------------------------ /token (público, PKCE)

    public function token(Request $request): JsonResponse
    {
        $grant = (string) $request->input('grant_type');
        $clientId = (string) $request->input('client_id');

        try {
            if ($this->oauth->findClient($clientId) === null) {
                throw new OAuthException('invalid_client', 'client_id desconocido.', 401);
            }

            $result = match ($grant) {
                'authorization_code' => $this->oauth->exchangeAuthorizationCode(
                    $clientId,
                    (string) $request->input('code'),
                    (string) $request->input('code_verifier'),
                    (string) $request->input('redirect_uri'),
                    $request->filled('resource') ? (string) $request->input('resource') : null,
                ),
                'refresh_token' => $this->oauth->refresh(
                    $clientId,
                    (string) $request->input('refresh_token'),
                    $request->filled('resource') ? (string) $request->input('resource') : null,
                ),
                default => throw new OAuthException('unsupported_grant_type', 'grant_type no soportado.'),
            };
        } catch (OAuthException $e) {
            return response()->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status)
                ->header('Cache-Control', 'no-store');
        }

        unset($result['_refresh_id']);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        if ($token !== '') {
            // RFC 7009: intenta como access y como refresh; respuesta 200 en cualquier caso.
            $this->oauth->revokeAccessToken($token);
            $this->oauth->revokeRefreshToken($token);
        }

        return response()->json(['revoked' => true]);
    }

    // ------------------------------------------------------ helpers

    /**
     * La conexión OAuth de ChatGPT es de alcance GLOBAL (todas las instituciones); por eso
     * solo un SUPER ADMIN puede autorizarla. Un administrador institucional NUNCA puede
     * escalar una conexión OAuth a acceso Global.
     */
    private function assertCanAuthorizeGlobal(Request $request): void
    {
        $user = $request->user();
        abort_if($user === null || ! $user->isSuperAdmin(), 403, 'Solo un super administrador puede autorizar una conexión Global.');
    }

    /**
     * Valida la petición de authorize y devuelve [cliente, params saneados].
     *
     * @return array{0: McpOauthClient, 1: array<string,string>}
     */
    private function parseAuthorizeRequest(Request $request): array
    {
        $clientId = (string) $request->input('client_id');
        $client = $this->oauth->findClient($clientId);
        abort_if($client === null, 400, 'client_id desconocido.');

        if ((string) $request->input('response_type') !== 'code') {
            abort(400, 'response_type debe ser "code".');
        }
        if ((string) $request->input('code_challenge_method') !== 'S256') {
            abort(400, 'code_challenge_method debe ser "S256" (PKCE plain no permitido).');
        }
        $codeChallenge = (string) $request->input('code_challenge');
        abort_if($codeChallenge === '', 400, 'Falta code_challenge.');

        $redirectUri = (string) $request->input('redirect_uri');
        abort_unless(in_array($redirectUri, $client->redirect_uris, true), 400, 'redirect_uri no registrado para este cliente.');

        // Scope: subconjunto de lo soportado; por defecto lectura.
        $requested = array_values(array_filter(explode(' ', (string) $request->input('scope', 'mcp:read'))));
        foreach ($requested as $s) {
            abort_unless(in_array($s, $this->oauth->scopesSupported(), true), 400, 'scope no soportado: '.$s);
        }
        if ($requested === []) {
            $requested = ['mcp:read'];
        }

        // resource (RFC 8707): si viene, debe ser exactamente el de este servidor.
        $resource = $request->filled('resource') ? (string) $request->input('resource') : $this->oauth->resource();
        abort_unless(hash_equals($this->oauth->resource(), $resource), 400, 'resource no coincide con este servidor MCP.');

        return [$client, [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => implode(' ', $requested),
            'state' => (string) $request->input('state', ''),
            'resource' => $resource,
        ]];
    }

    /**
     * @param  array<string,string>  $params
     */
    private function appendQuery(string $uri, array $params): string
    {
        $params = array_filter($params, fn (string $v): bool => $v !== '');

        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }

    private function dcrError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400);
    }
}
