<?php

declare(strict_types=1);

namespace Modules\Mcp\Services;

use Illuminate\Support\Str;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Mcp\Models\McpClient;

/**
 * Lógica ÚNICA de credenciales del servidor MCP: la usan por igual el comando
 * `mcp:client` (CLI) y la pantalla de administración del panel, para no
 * duplicar la seguridad. El token en claro solo existe aquí en el momento de
 * emitirlo (mcp_ + 48 aleatorios); en BD vive solo su SHA-256. Cada ciclo de
 * vida (create/rotate/revoke) queda auditado en mcp_audit_logs.
 */
final class McpClientManager
{
    /**
     * Crea un cliente y devuelve [modelo, token en claro]. institutionId null =
     * cliente GLOBAL (transversal); con valor = acotado a esa institución.
     *
     * @return array{0: McpClient, 1: string}
     */
    public function create(string $name, ?int $institutionId = null): array
    {
        $token = $this->newToken();
        $client = McpClient::query()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'institution_id' => $institutionId,
            'is_active' => true,
        ]);
        $this->audit($client, 'mcp.client.create');

        return [$client, $token];
    }

    /** Rota el token (invalida el anterior de inmediato) y devuelve el nuevo en claro. */
    public function rotate(McpClient $client): string
    {
        $token = $this->newToken();
        $client->forceFill(['token_hash' => hash('sha256', $token), 'is_active' => true])->save();
        $this->audit($client, 'mcp.client.rotate');

        return $token;
    }

    /** Revoca (desactiva) el cliente: el acceso se deniega en la siguiente petición. */
    public function revoke(McpClient $client): void
    {
        $client->forceFill(['is_active' => false])->save();
        $this->audit($client, 'mcp.client.revoke');
    }

    public function newToken(): string
    {
        return 'mcp_'.Str::random(48);
    }

    /** Deja rastro del ciclo de vida en el mismo registro de auditoría del MCP. */
    private function audit(McpClient $client, string $tool): void
    {
        McpAuditLog::query()->create([
            'mcp_client_id' => $client->id,
            'institution_id' => $client->institution_id,
            'correlation_id' => (string) Str::uuid(),
            'tool' => $tool,
            'action' => null,
            'resource' => 'McpClient:'.$client->name,
            'params' => ['scope' => $client->isGlobal() ? 'global' : 'institution:'.$client->institution_id],
            'status' => 'ok',
            'error' => null,
            'duration_ms' => 0,
        ]);
    }
}
