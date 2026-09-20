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
 * @property string $assistant_type
 * @property string $profile
 * @property bool $allow_write
 * @property string $auth_kind
 * @property string $token_hash
 * @property int|null $institution_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class McpClient extends Model
{
    /** Presets visibles en la UI (etiqueta del asistente). */
    public const ASSISTANTS = ['chatgpt', 'claude', 'claude_code', 'generic'];

    /** Perfiles de acceso reales (aplicados en el servidor, no solo en la UI). */
    public const PROFILE_INSPECTION = 'inspection';

    public const PROFILE_TECHNICAL = 'technical';

    protected $fillable = [
        'name', 'assistant_type', 'profile', 'allow_write', 'auth_kind',
        'token_hash', 'institution_id', 'is_active', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_write' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->institution_id === null;
    }

    /** Perfil efectivo (los clientes legados sin perfil se tratan como técnicos). */
    public function effectiveProfile(): string
    {
        return $this->profile ?: self::PROFILE_TECHNICAL;
    }

    /** ¿Puede ejecutar herramientas de escritura? Solo technical con allow_write. */
    public function canWrite(): bool
    {
        return $this->effectiveProfile() === self::PROFILE_TECHNICAL && (bool) $this->allow_write;
    }
}
