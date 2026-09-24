<?php

declare(strict_types=1);

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Support\McpContext;
use Modules\Mcp\Support\McpToolException;
use Modules\Mcp\Support\SecretRedactor;
use Modules\Mcp\Support\ToolRegistry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Endpoint MCP (Streamable HTTP, modo JSON puro): un POST = un mensaje
 * JSON-RPC 2.0. Implementación propia y mínima del protocolo (initialize,
 * ping, tools/list, tools/call) — sin dependencias nuevas ni procesos
 * persistentes: apto para Hostinger. Cada tools/call queda AUDITADO en
 * mcp_audit_logs con parámetros saneados (nunca secretos) y correlation id.
 */
final class McpController
{
    private const PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];

    public function __construct(private readonly ToolRegistry $tools) {}

    public function handle(Request $request): Response
    {
        /** @var McpClient $client */
        $client = $request->attributes->get('mcp_client');

        $message = json_decode($request->getContent(), true);
        if (! is_array($message)) {
            return $this->error(null, -32700, 'JSON inválido.');
        }
        if (array_is_list($message)) {
            return $this->error(null, -32600, 'Este servidor no soporta batches JSON-RPC: envía un mensaje por request.');
        }

        $method = (string) ($message['method'] ?? '');
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // Notificaciones (sin id): se aceptan sin cuerpo de respuesta.
        if (str_starts_with($method, 'notifications/')) {
            $this->debugRequest($method, $client, null, 202);

            return response()->noContent(202);
        }

        /** @var array<int,string>|null $scopes scopes del token OAuth (null = Bearer estático) */
        $scopes = $request->attributes->get('mcp_oauth_scopes');

        if ($method === 'tools/list') {
            $tools = $this->tools->list($client);
            $this->debugRequest($method, $client, count($tools), 200);

            return $this->result($id, ['tools' => $tools]);
        }

        $this->debugRequest($method, $client, null, 200);

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, (object) []),
            'tools/call' => $this->toolsCall($client, $id, $params, $scopes),
            default => $this->error($id, -32601, 'Método no soportado: '.$method),
        };
    }

    /** Log diagnóstico TEMPORAL de la petición MCP: metadatos NO sensibles (sin tokens/args). */
    private function debugRequest(string $method, ?McpClient $client, ?int $toolsCount, int $httpStatus): void
    {
        if (! config('mcp.debug_requests', false)) {
            return;
        }
        Log::info('mcp.request', array_filter([
            'method' => $method !== '' ? $method : '(sin método)',
            'mcp_client_id' => $client?->id,
            'profile' => $client?->effectiveProfile(),
            'institution_id' => $client?->institution_id,
            'tools_count' => $toolsCount,
            'http' => $httpStatus,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'mca-crm-mcp', 'title' => 'MCA CRM', 'version' => '1.0.0'],
            'instructions' => 'Servidor MCP privado de MCA CRM (transversal a todos los módulos). '
                .'Empieza por crm_overview; localiza funcionalidades con crm_search/crm_code_search; '
                .'consulta datos con crm_query/crm_record_get; opera con crm_execute (las operaciones '
                .'peligrosas y los borrados exigen confirm: true). Los secretos siempre vuelven enmascarados.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int,string>|null  $scopes
     */
    private function toolsCall(McpClient $client, mixed $id, array $params, ?array $scopes = null): Response
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $context = new McpContext($client, (string) Str::uuid(), $scopes);
        $started = microtime(true);

        try {
            $payload = $this->tools->call($name, $arguments, $context);
            $this->audit($client, $context, $name, $arguments, 'ok', null, $started);

            return $this->result($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]],
                'isError' => false,
                '_meta' => ['correlationId' => $context->correlationId],
            ]);
        } catch (McpToolException $e) {
            $this->audit($client, $context, $name, $arguments, 'error', $e->getMessage(), $started);

            $meta = ['correlationId' => $context->correlationId];
            // Challenge OAuth a nivel de tool (p. ej. scope insuficiente): ChatGPT sabe que
            // debe (re)autorizar. Solo aplica a conexiones OAuth.
            if ($e->challenge !== null && $context->scopes !== null) {
                $meta['mcp/www_authenticate'] = $e->challenge;
            }

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Error: '.$e->getMessage()]],
                'isError' => true,
                '_meta' => $meta,
            ]);
        } catch (Throwable $e) {
            // Error interno: al asistente solo llega un mensaje genérico + correlation id;
            // el detalle queda en el log del servidor (sin parámetros sensibles).
            Log::error('mcp: error interno en tools/call', [
                'tool' => $name,
                'correlation_id' => $context->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->audit($client, $context, $name, $arguments, 'error', 'internal: '.$e::class, $started);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Error interno del servidor MCP (correlation '.$context->correlationId.'). Revisa crm_logs.']],
                'isError' => true,
                '_meta' => ['correlationId' => $context->correlationId],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function audit(McpClient $client, McpContext $context, string $tool, array $arguments, string $status, ?string $error, float $started): void
    {
        try {
            $institutionId = null;
            try {
                $institutionId = $context->institutionId($arguments);
            } catch (McpToolException) {
                // institución en conflicto: se audita sin ella
            }

            $resource = null;
            if (isset($arguments['model'])) {
                $resource = (string) $arguments['model'].(isset($arguments['id']) ? '#'.$arguments['id'] : '');
            } elseif (isset($arguments['table'])) {
                $resource = 'table:'.(string) $arguments['table'];
            } elseif (isset($arguments['path'])) {
                $resource = 'file:'.(string) $arguments['path'];
            }

            McpAuditLog::query()->create([
                'mcp_client_id' => $client->id,
                'institution_id' => $institutionId,
                'correlation_id' => $context->correlationId,
                'tool' => $tool !== '' ? $tool : '(sin nombre)',
                'action' => isset($arguments['operation']) ? (string) $arguments['operation'] : null,
                'resource' => $resource,
                'params' => SecretRedactor::redact($arguments),
                'status' => $status,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca tumba la llamada, pero sí deja rastro en el log.
            Log::warning('mcp: no se pudo escribir la auditoría', ['error' => $e->getMessage()]);
        }
    }

    private function result(mixed $id, mixed $result): Response
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): Response
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
