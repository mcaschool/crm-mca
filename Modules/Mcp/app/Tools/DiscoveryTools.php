<?php

declare(strict_types=1);

namespace Modules\Mcp\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Support\CodeSearcher;
use Modules\Mcp\Support\McpContext;
use Modules\Mcp\Support\McpToolException;
use Modules\Mcp\Support\ModelAtlas;
use Modules\Mcp\Support\OperationRegistry;
use Modules\Mcp\Support\SecretRedactor;

/**
 * Herramientas de descubrimiento y arquitectura: qué existe en el CRM, dónde
 * está y cómo se llama — transversal a todos los módulos, sin registros
 * manuales. Todo lo devuelto pasa por SecretRedactor donde aplica.
 */
final class DiscoveryTools
{
    public function __construct(private readonly OperationRegistry $operations) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function overview(array $args, McpContext $ctx): array
    {
        $statuses = json_decode((string) @file_get_contents(base_path('modules_statuses.json')), true);

        return [
            'app' => [
                'name' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'modules' => is_array($statuses) ? $statuses : [],
            'counts' => [
                'routes' => count(Route::getRoutes()->getRoutes()),
                'models' => count(ModelAtlas::all()),
                'tables' => count($this->tables()),
                'operations' => count($this->operations->catalog()),
            ],
            'institutions' => $ctx->run([], fn () => Institution::query()->get(['id', 'name', 'slug'])->toArray()),
            'client' => [
                'name' => $ctx->client->name,
                'scope' => $ctx->client->isGlobal() ? 'global' : 'institution '.$ctx->client->institution_id,
            ],
            'runtime' => [
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'mail' => (string) config('mail.default'),
                'multi_institution' => (bool) config('crm.multi_institution'),
                'embedded_signup_enabled' => (bool) config('social.embedded_signup.enabled'),
            ],
            'git' => OpsTools::gitSummary(),
        ];
    }

    /**
     * Búsqueda federada: código, rutas, claves de config (solo nombres),
     * modelos, tablas y operaciones — para localizar dónde vive cualquier cosa.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function search(array $args, McpContext $ctx): array
    {
        $term = trim((string) ($args['term'] ?? ''));
        if ($term === '') {
            throw new McpToolException('Falta term.');
        }
        $needle = strtolower($term);

        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = (string) $route->getActionName();
            if (stripos($route->uri(), $term) !== false
                || stripos((string) $route->getName(), $term) !== false
                || stripos($action, $term) !== false) {
                $routes[] = ['uri' => $route->uri(), 'name' => $route->getName(), 'action' => $action];
                if (count($routes) >= 15) {
                    break;
                }
            }
        }

        $configKeys = [];
        foreach (Arr::dot(config()->all()) as $key => $value) {
            if (str_contains(strtolower((string) $key), $needle)) {
                $configKeys[] = $key; // solo el NOMBRE de la clave, nunca el valor
                if (count($configKeys) >= 20) {
                    break;
                }
            }
        }

        return [
            'term' => $term,
            'code' => CodeSearcher::search($term, null, 20),
            'routes' => $routes,
            'config_keys' => $configKeys,
            'models' => array_values(array_filter(array_keys(ModelAtlas::all()), fn (string $k) => str_contains(strtolower($k), $needle))),
            'tables' => array_values(array_filter($this->tables(), fn (string $t) => str_contains(strtolower($t), $needle))),
            'operations' => array_values(array_filter(array_keys($this->operations->catalog()), fn (string $o) => str_contains(strtolower($o), $needle))),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function routes(array $args, McpContext $ctx): array
    {
        $filter = strtolower(trim((string) ($args['filter'] ?? '')));
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $row = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => (string) $route->getActionName(),
                'middleware' => array_map(
                    fn ($m) => is_string($m) ? $m : get_debug_type($m),
                    $route->gatherMiddleware(),
                ),
            ];
            if ($filter !== '' && ! str_contains(strtolower(json_encode($row) ?: ''), $filter)) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= (int) config('mcp.max_rows', 200)) {
                break;
            }
        }

        return ['count' => count($out), 'routes' => $out];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function models(array $args, McpContext $ctx): array
    {
        $name = trim((string) ($args['model'] ?? ''));
        if ($name === '') {
            $list = [];
            foreach (ModelAtlas::all() as $key => $class) {
                $instance = new $class;
                $list[] = [
                    'model' => $key,
                    'table' => $instance->getTable(),
                    'institution_scoped' => ModelAtlas::isInstitutionScoped($class),
                ];
            }

            return ['count' => count($list), 'models' => $list];
        }

        $class = ModelAtlas::resolve($name);
        $instance = new $class;
        $count = null;
        try {
            $count = (int) $ctx->run($args, fn () => $class::query()->count());
        } catch (\Throwable) {
            // sin tabla aún, o requiere contexto: el conteo es opcional
        }

        return [
            'model' => $name,
            'class' => $class,
            'table' => $instance->getTable(),
            'institution_scoped' => ModelAtlas::isInstitutionScoped($class),
            'fillable' => $instance->getFillable(),
            'casts' => array_map('strval', $instance->getCasts()),
            'relations' => ModelAtlas::relations($class),
            'records_in_context' => $count,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function schema(array $args, McpContext $ctx): array
    {
        $table = trim((string) ($args['table'] ?? ''));
        if ($table === '') {
            $rows = DB::select(
                'SELECT table_name AS name, table_rows AS approx_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            );

            return ['count' => count($rows), 'tables' => array_map(fn ($r) => (array) $r, $rows)];
        }

        $this->assertTableName($table);
        $columns = DB::select(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_key AS `key`, column_default AS `default`, extra
             FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        );
        if ($columns === []) {
            throw new McpToolException('Tabla desconocida: '.$table);
        }
        $indexes = DB::select('SHOW INDEX FROM `'.$table.'`');

        return [
            'table' => $table,
            'columns' => array_map(fn ($c) => (array) $c, $columns),
            'indexes' => array_map(fn ($i) => Arr::only((array) $i, ['Key_name', 'Column_name', 'Non_unique', 'Seq_in_index']), $indexes),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function config(array $args, McpContext $ctx): array
    {
        $key = trim((string) ($args['key'] ?? ''));
        if ($key === '') {
            return ['roots' => array_keys(config()->all())];
        }
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $key) !== 1) {
            throw new McpToolException('Clave de config inválida.');
        }

        $value = config($key, '__missing__');
        if ($value === '__missing__') {
            throw new McpToolException('No existe la clave de config "'.$key.'".');
        }
        if (SecretRedactor::isSecretKey($key)) {
            $value = SecretRedactor::redact([$key => $value])[$key];
        } elseif (is_array($value)) {
            $value = SecretRedactor::redact($value);
        }

        return ['key' => $key, 'value' => $value];
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        $rows = DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()');

        return array_map(fn ($r) => (string) ((array) $r)['name'], $rows);
    }

    private function assertTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new McpToolException('Nombre de tabla inválido.');
        }
    }
}
