<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cliente autenticado del servidor MCP. token_hash = sha256 del Bearer (el
 * token en claro solo existe en el momento de emitirlo). institution_id null =
 * cliente GLOBAL (transversal); con valor = acotado a esa institución. Tabla de
 * autenticación: sin scope global (el aislamiento lo impone McpContext).
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property int|null $institution_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class McpClient extends Model
{
    protected $fillable = ['name', 'token_hash', 'institution_id', 'is_active', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->institution_id === null;
    }
}
