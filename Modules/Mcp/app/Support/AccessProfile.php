<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Modules\Mcp\Models\McpClient;

/**
 * Perfiles de acceso del servidor MCP: definen QUÉ herramientas puede LISTAR y
 * EJECUTAR una conexión según su perfil. Es la fuente única de verdad y se
 * aplica en el BACKEND (McpController/ToolRegistry): ocultar en la UI no basta.
 *
 *  - INSPECTION (ChatGPT/Claude): solo herramientas de lectura/inspección.
 *  - TECHNICAL (Claude Code): lectura técnica completa; las herramientas de
 *    ESCRITURA solo si el administrador activó allow_write en esa conexión.
 * Las confirmaciones de seguridad existentes (confirm:true) se mantienen aparte.
 */
final class AccessProfile
{
    /** Herramientas de LECTURA/inspección: disponibles en ambos perfiles. */
    public const READ_TOOLS = [
        'crm_overview', 'crm_search', 'crm_routes', 'crm_models', 'crm_schema',
        'crm_config', 'crm_query', 'crm_record_get', 'crm_logs', 'crm_jobs',
        'crm_scheduler', 'crm_integrations', 'crm_health', 'crm_code_search',
        'crm_code_read', 'crm_git_status',
    ];

    /** Herramientas de ESCRITURA / cambio de estado: nunca en INSPECTION. */
    public const WRITE_TOOLS = [
        'crm_record_create', 'crm_record_update', 'crm_record_delete', 'crm_execute',
    ];

    /** ¿La conexión puede usar esta herramienta? (perfil + allow_write). */
    public static function allows(McpClient $client, string $tool): bool
    {
        if (in_array($tool, self::READ_TOOLS, true)) {
            return true; // lectura: ambos perfiles
        }
        if (in_array($tool, self::WRITE_TOOLS, true)) {
            return $client->canWrite(); // solo technical + allow_write
        }

        return false; // herramienta desconocida: denegada por defecto
    }

    /** Mensaje uniforme cuando una herramienta no está permitida para la conexión. */
    public static function denialMessage(McpClient $client, string $tool): string
    {
        if (in_array($tool, self::WRITE_TOOLS, true) && $client->effectiveProfile() === McpClient::PROFILE_TECHNICAL) {
            return __('Esta conexión tiene las acciones técnicas de escritura desactivadas. Actívalas en «Administrar permisos» para usar :tool.', ['tool' => $tool]);
        }

        return __('La herramienta :tool no está disponible para este tipo de conexión (perfil de solo lectura).', ['tool' => $tool]);
    }
}
