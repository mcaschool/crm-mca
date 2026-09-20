<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría de llamadas MCP (actor = MCP). Los params llegan ya
 * SANEADOS por SecretRedactor: jamás se persisten secretos. Sin scope global:
 * la auditoría se escribe también para operaciones transversales.
 *
 * @property int $mcp_client_id
 * @property int|null $institution_id
 * @property string $correlation_id
 * @property string $tool
 * @property string|null $action
 * @property string|null $resource
 * @property array<string,mixed>|null $params
 * @property string $status
 * @property string|null $error
 * @property int $duration_ms
 */
class McpAuditLog extends Model
{
    protected $fillable = [
        'mcp_client_id', 'institution_id', 'correlation_id', 'tool', 'action',
        'resource', 'params', 'status', 'error', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }
}
