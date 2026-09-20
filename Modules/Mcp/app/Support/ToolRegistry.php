<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Modules\Mcp\Tools\CodeTools;
use Modules\Mcp\Tools\DataTools;
use Modules\Mcp\Tools\DiscoveryTools;
use Modules\Mcp\Tools\OpsTools;

/**
 * Catálogo ÚNICO de herramientas MCP: generales y dinámicas (nunca una tool
 * por módulo). Cada entrada define nombre, descripción, inputSchema (JSON
 * Schema) y el handler. Añadir capacidades = añadir entradas aquí.
 */
final class ToolRegistry
{
    public function __construct(
        private readonly DiscoveryTools $discovery,
        private readonly DataTools $data,
        private readonly OpsTools $ops,
        private readonly CodeTools $code,
    ) {}

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function list(): array
    {
        return array_map(
            fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ],
            $this->definitions(),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, McpContext $context): array
    {
        foreach ($this->definitions() as $tool) {
            if ($tool['name'] === $name) {
                /** @var array<string, mixed> */
                return ($tool['handler'])($arguments, $context);
            }
        }

        throw new McpToolException('Herramienta desconocida: '.$name.'.');
    }

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>, handler: callable}>
     */
    private function definitions(): array
    {
        $institution = ['institution_id' => ['type' => 'integer', 'description' => 'Institución sobre la que operar (clientes globales; los acotados la tienen fija).']];

        return [
            [
                'name' => 'crm_overview',
                'description' => 'Panorama del CRM: versiones, módulos, contadores, instituciones, flags, git y alcance del cliente MCP.',
                'inputSchema' => $this->schema([]),
                'handler' => $this->discovery->overview(...),
            ],
            [
                'name' => 'crm_search',
                'description' => 'Búsqueda federada de una funcionalidad: código, rutas, claves de config, modelos, tablas y operaciones. Úsala para localizar dónde está implementado algo.',
                'inputSchema' => $this->schema(['term' => ['type' => 'string']], ['term']),
                'handler' => $this->discovery->search(...),
            ],
            [
                'name' => 'crm_routes',
                'description' => 'Rutas Laravel con métodos, controlador/acción y middleware. filter filtra por subcadena.',
                'inputSchema' => $this->schema(['filter' => ['type' => 'string']]),
                'handler' => $this->discovery->routes(...),
            ],
            [
                'name' => 'crm_models',
                'description' => 'Modelos Eloquent de todo el CRM. Sin model: lista (Modulo.Clase, tabla, scope). Con model: fillable, casts, relaciones y conteo en contexto.',
                'inputSchema' => $this->schema(['model' => ['type' => 'string']] + $institution),
                'handler' => $this->discovery->models(...),
            ],
            [
                'name' => 'crm_schema',
                'description' => 'Esquema de base de datos. Sin table: todas las tablas con filas aproximadas. Con table: columnas e índices.',
                'inputSchema' => $this->schema(['table' => ['type' => 'string']]),
                'handler' => $this->discovery->schema(...),
            ],
            [
                'name' => 'crm_config',
                'description' => 'Configuración efectiva de Laravel por clave (p. ej. social.graph_version). Los valores secretos vuelven enmascarados (configured/masked), nunca completos.',
                'inputSchema' => $this->schema(['key' => ['type' => 'string']]),
                'handler' => $this->discovery->config(...),
            ],
            [
                'name' => 'crm_query',
                'description' => 'Consulta de LECTURA sobre cualquier tabla: where [[col,op,valor]...], select, order_by, limit y aggregate {fn,column,group_by}. Respeta institution_id y redacta columnas secretas.',
                'inputSchema' => $this->schema([
                    'table' => ['type' => 'string'],
                    'select' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'where' => ['type' => 'array', 'items' => ['type' => 'array']],
                    'order_by' => ['type' => 'string'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'limit' => ['type' => 'integer'],
                    'aggregate' => ['type' => 'object'],
                ] + $institution, ['table']),
                'handler' => $this->data->query(...),
            ],
            [
                'name' => 'crm_record_get',
                'description' => 'Un registro por modelo (Modulo.Clase) e id, con relaciones opcionales (with).',
                'inputSchema' => $this->schema([
                    'model' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'with' => ['type' => 'array', 'items' => ['type' => 'string']],
                ] + $institution, ['model', 'id']),
                'handler' => $this->data->recordGet(...),
            ],
            [
                'name' => 'crm_record_create',
                'description' => 'Crea un registro vía Eloquent (respeta fillable y scope de institución). Modelos de institución exigen institution_id en clientes globales.',
                'inputSchema' => $this->schema([
                    'model' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                ] + $institution, ['model', 'data']),
                'handler' => $this->data->recordCreate(...),
            ],
            [
                'name' => 'crm_record_update',
                'description' => 'Actualiza campos de un registro vía Eloquent. Campos de contraseña/2FA/hashes no son escribibles.',
                'inputSchema' => $this->schema([
                    'model' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'data' => ['type' => 'object'],
                ] + $institution, ['model', 'id', 'data']),
                'handler' => $this->data->recordUpdate(...),
            ],
            [
                'name' => 'crm_record_delete',
                'description' => 'Elimina un registro. DESTRUCTIVA: exige confirm: true.',
                'inputSchema' => $this->schema([
                    'model' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'confirm' => ['type' => 'boolean'],
                ] + $institution, ['model', 'id']),
                'handler' => $this->data->recordDelete(...),
            ],
            [
                'name' => 'crm_execute',
                'description' => 'Ejecuta operaciones REALES del CRM (sync de plantillas, reanudar publicaciones, syncs de Coexistence, colas, caches, simular webhooks…). Sin operation: devuelve el catálogo. Las peligrosas exigen confirm: true.',
                'inputSchema' => $this->schema([
                    'operation' => ['type' => 'string'],
                    'params' => ['type' => 'object'],
                    'confirm' => ['type' => 'boolean'],
                ] + $institution),
                'handler' => $this->ops->execute(...),
            ],
            [
                'name' => 'crm_logs',
                'description' => 'Logs de Laravel: lista de archivos y últimas N líneas con filtros search/level/date.',
                'inputSchema' => $this->schema([
                    'file' => ['type' => 'string'],
                    'lines' => ['type' => 'integer'],
                    'search' => ['type' => 'string'],
                    'level' => ['type' => 'string'],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ]),
                'handler' => $this->ops->logs(...),
            ],
            [
                'name' => 'crm_jobs',
                'description' => 'Colas: pendientes por queue y jobs fallidos (failed_id para el detalle con excepción).',
                'inputSchema' => $this->schema(['failed_id' => ['type' => 'string']]),
                'handler' => $this->ops->jobs(...),
            ],
            [
                'name' => 'crm_scheduler',
                'description' => 'Tareas programadas (schedule:list).',
                'inputSchema' => $this->schema([]),
                'handler' => $this->ops->scheduler(...),
            ],
            [
                'name' => 'crm_integrations',
                'description' => 'Integraciones y canales sociales: proveedor, estado, conexión y credenciales ENMASCARADAS; webhooks configurados.',
                'inputSchema' => $this->schema($institution),
                'handler' => $this->ops->integrations(...),
            ],
            [
                'name' => 'crm_health',
                'description' => 'Salud del sistema: BD, storage, disco, colas, jobs fallidos, último entrante social, extensiones PHP.',
                'inputSchema' => $this->schema([]),
                'handler' => $this->ops->health(...),
            ],
            [
                'name' => 'crm_code_search',
                'description' => 'Busca en el código del repo (app, Modules, config, routes, resources, database, tests). pattern: subcadena o regex /.../',
                'inputSchema' => $this->schema([
                    'pattern' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ], ['pattern']),
                'handler' => $this->code->codeSearch(...),
            ],
            [
                'name' => 'crm_code_read',
                'description' => 'Lee un archivo del repo (rango from/to). Nunca sirve .env ni secretos.',
                'inputSchema' => $this->schema([
                    'path' => ['type' => 'string'],
                    'from' => ['type' => 'integer'],
                    'to' => ['type' => 'integer'],
                ], ['path']),
                'handler' => $this->code->codeRead(...),
            ],
            [
                'name' => 'crm_git_status',
                'description' => 'Estado git del despliegue: rama, HEAD, working tree y últimos commits.',
                'inputSchema' => $this->schema([]),
                'handler' => $this->ops->gitStatus(...),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
