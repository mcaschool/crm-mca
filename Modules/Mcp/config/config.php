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
];
