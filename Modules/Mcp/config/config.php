<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Módulo Mcp — servidor MCP privado de MCA CRM
|--------------------------------------------------------------------------
| Endpoint: POST /api/mcp (Streamable HTTP, JSON-RPC 2.0, sin SSE: apto para
| hosting compartido sin procesos persistentes). Autenticación por Bearer de
| clientes registrados en mcp_clients (hash SHA-256; se emiten/rotan/revocan
| con `php artisan mcp:client`). Sin variables nuevas de .env: el interruptor
| es tener clientes activos o no.
*/

return [
    'name' => 'Mcp',

    // Tope duro de filas devueltas por crm_query / listados.
    'max_rows' => 200,

    // Tope de líneas devueltas por crm_code_read y crm_logs.
    'max_lines' => 400,

    // Directorios del repo donde crm_code_search / crm_code_read pueden entrar.
    'code_roots' => ['app', 'Modules', 'config', 'routes', 'database', 'resources', 'tests', 'lang', 'public'],

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.1 para ChatGPT (Authorization Code + PKCE S256 + DCR)
    |--------------------------------------------------------------------------
    | Capa OAuth para que ChatGPT se conecte al MISMO endpoint /api/mcp. NO afecta
    | al Bearer estático de Claude Code (rama 1 de VerifyMcpToken). issuer/resource
    | se derivan de APP_URL si no se fijan por env (nunca hardcodeados en código).
    | Solo public clients (PKCE, sin client_secret). Sin CIMD en esta fase.
    */
    'oauth' => [
        'enabled' => (bool) env('MCP_OAUTH_ENABLED', true),
        // Vacíos → se resuelven a APP_URL / APP_URL.'/api/mcp' en OAuthService.
        'issuer' => env('MCP_OAUTH_ISSUER'),
        'resource' => env('MCP_OAUTH_RESOURCE'),
        'scopes_supported' => ['mcp:read', 'mcp:write', 'offline_access'],
        'access_token_ttl' => (int) env('MCP_OAUTH_ACCESS_TTL', 3600),        // 1 h
        'refresh_token_ttl' => (int) env('MCP_OAUTH_REFRESH_TTL', 2592000),   // 30 días
        'auth_code_ttl' => (int) env('MCP_OAUTH_CODE_TTL', 120),              // 2 min
        // Hosts de redirect permitidos para DCR (solo HTTPS; host exacto o subdominio).
        'allowed_redirect_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MCP_OAUTH_REDIRECT_HOSTS', 'chatgpt.com,chat.openai.com,openai.com,platform.openai.com'))
        ))),
    ],
];
