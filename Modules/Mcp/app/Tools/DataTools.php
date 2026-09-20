<?php

declare(strict_types=1);

namespace Modules\Mcp\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Mcp\Support\McpContext;
use Modules\Mcp\Support\McpToolException;
use Modules\Mcp\Support\ModelAtlas;
use Modules\Mcp\Support\SecretRedactor;

/**
 * Datos del CRM: consultas de lectura (query builder acotado) y operaciones
 * CRUD vía Eloquent (respetando fillable, casts y el scope de institución).
 * Todo lo devuelto pasa por SecretRedactor; los campos de credenciales de
 * usuarios y del propio MCP no son escribibles desde aquí.
 */
final class DataTools
{
    /** Columnas jamás escribibles vía MCP (además de la denylist de modelos). */
    private const WRITE_DENIED_FIELDS = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token_hash',
    ];

    private const WHERE_OPS = ['=', '!=', '<', '<=', '>', '>=', 'like', 'in', 'not_in', 'null', 'not_null'];

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function query(array $args, McpContext $ctx): array
    {
        $table = trim((string) ($args['table'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || ! Schema::hasTable($table)) {
            throw new McpToolException('Tabla inválida o inexistente: '.$table);
        }

        $limit = min(max((int) ($args['limit'] ?? 25), 1), (int) config('mcp.max_rows', 200));
        $query = DB::table($table);

        // Aislamiento multi-institución: si la tabla lo soporta y hay institución
        // en el contexto, se aplica SIEMPRE; el modo global (cliente global sin
        // institution_id) consulta transversalmente de forma deliberada.
        $institutionId = $ctx->institutionId($args);
        if ($institutionId !== null && Schema::hasColumn($table, 'institution_id')) {
            $query->where('institution_id', $institutionId);
        }

        foreach (is_array($args['where'] ?? null) ? $args['where'] : [] as $clause) {
            if (! is_array($clause) || count($clause) < 2) {
                throw new McpToolException('Cada where debe ser [columna, operador, valor?].');
            }
            [$column, $op] = [$this->columnName($table, (string) $clause[0]), strtolower((string) $clause[1])];
            $value = $clause[2] ?? null;
            if (! in_array($op, self::WHERE_OPS, true)) {
                throw new McpToolException('Operador no soportado: '.$op.' (usa '.implode(', ', self::WHERE_OPS).').');
            }
            match ($op) {
                'in' => $query->whereIn($column, is_array($value) ? $value : [$value]),
                'not_in' => $query->whereNotIn($column, is_array($value) ? $value : [$value]),
                'null' => $query->whereNull($column),
                'not_null' => $query->whereNotNull($column),
                default => $query->where($column, $op, $value),
            };
        }

        // Agregación opcional: count/sum/avg/min/max (+ group_by).
        $aggregate = is_array($args['aggregate'] ?? null) ? $args['aggregate'] : null;
        if ($aggregate !== null) {
            $fn = strtolower((string) ($aggregate['fn'] ?? 'count'));
            if (! in_array($fn, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                throw new McpToolException('Función de agregado no soportada: '.$fn);
            }
            $column = $fn === 'count' ? '*' : $this->columnName($table, (string) ($aggregate['column'] ?? 'id'));
            $groupBy = isset($aggregate['group_by']) ? $this->columnName($table, (string) $aggregate['group_by']) : null;

            if ($groupBy === null) {
                $value = $fn === 'count' ? $query->count() : $query->{$fn}($column);

                return ['table' => $table, 'aggregate' => [$fn => is_numeric($value) ? $value + 0 : $value]];
            }
            $rows = $query->selectRaw('`'.$groupBy.'` as group_value, '.$fn.'('.($column === '*' ? '*' : '`'.$column.'`').') as value')
                ->groupBy($groupBy)->orderByDesc('value')->limit($limit)->get();

            return ['table' => $table, 'group_by' => $groupBy, 'rows' => $rows->map(fn ($r) => (array) $r)->all()];
        }

        $select = is_array($args['select'] ?? null) && $args['select'] !== []
            ? array_map(fn ($c) => $this->columnName($table, (string) $c), $args['select'])
            : ['*'];
        if (isset($args['order_by'])) {
            $query->orderBy(
                $this->columnName($table, (string) $args['order_by']),
                strtolower((string) ($args['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            );
        }

        $rows = $query->select($select)->limit($limit)->get()
            ->map(fn ($row) => SecretRedactor::redact((array) $row))
            ->all();

        return ['table' => $table, 'count' => count($rows), 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function recordGet(array $args, McpContext $ctx): array
    {
        $class = ModelAtlas::resolve((string) ($args['model'] ?? ''));
        $id = (int) ($args['id'] ?? 0);
        $with = array_values(array_filter(is_array($args['with'] ?? null) ? $args['with'] : [], 'is_string'));

        return $ctx->run($args, function () use ($class, $id, $with, $args): array {
            $record = $class::query()->with($with)->find($id);
            if ($record === null) {
                throw new McpToolException('Registro no encontrado: '.(string) $args['model'].'#'.$id.' (en el contexto de institución actual).');
            }

            return [
                'model' => (string) $args['model'],
                'id' => $id,
                'attributes' => SecretRedactor::redact($record->toArray()),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function recordCreate(array $args, McpContext $ctx): array
    {
        [$class, $data] = $this->writableInput($args);

        if (ModelAtlas::isInstitutionScoped($class) && $ctx->institutionId($args) === null) {
            throw new McpToolException('Este modelo pertenece a una institución: indica institution_id.');
        }

        return $ctx->run($args, function () use ($class, $data, $args): array {
            $record = new $class;
            $record->fill($data);
            $this->save($record);

            return [
                'created' => true,
                'model' => (string) $args['model'],
                'id' => $record->getKey(),
                'attributes' => SecretRedactor::redact($record->refresh()->toArray()),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function recordUpdate(array $args, McpContext $ctx): array
    {
        [$class, $data] = $this->writableInput($args);
        $id = (int) ($args['id'] ?? 0);

        return $ctx->run($args, function () use ($class, $data, $id, $args): array {
            $record = $class::query()->find($id);
            if ($record === null) {
                throw new McpToolException('Registro no encontrado en el contexto actual.');
            }
            $record->fill($data);
            $changed = array_keys($record->getDirty());
            $this->save($record);

            return [
                'updated' => true,
                'model' => (string) $args['model'],
                'id' => $id,
                'changed' => $changed,
                'attributes' => SecretRedactor::redact($record->refresh()->toArray()),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function recordDelete(array $args, McpContext $ctx): array
    {
        $class = ModelAtlas::resolve((string) ($args['model'] ?? ''));
        $this->assertWritableModel($class);
        if (($args['confirm'] ?? null) !== true) {
            throw new McpToolException('Operación destructiva: repite la llamada con confirm: true para eliminar.');
        }
        $id = (int) ($args['id'] ?? 0);

        return $ctx->run($args, function () use ($class, $id, $args): array {
            $record = $class::query()->find($id);
            if ($record === null) {
                throw new McpToolException('Registro no encontrado en el contexto actual.');
            }
            $record->delete();

            return ['deleted' => true, 'model' => (string) $args['model'], 'id' => $id];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: class-string<Model>, 1: array<string, mixed>}
     */
    private function writableInput(array $args): array
    {
        $class = ModelAtlas::resolve((string) ($args['model'] ?? ''));
        $this->assertWritableModel($class);

        $data = is_array($args['data'] ?? null) ? $args['data'] : [];
        unset($data['id'], $data['created_at'], $data['updated_at']);
        if ($data === []) {
            throw new McpToolException('Falta data (objeto con los campos a escribir).');
        }
        foreach (array_keys($data) as $field) {
            if (in_array(strtolower((string) $field), self::WRITE_DENIED_FIELDS, true)) {
                throw new McpToolException('El campo "'.$field.'" no es escribible vía MCP.');
            }
        }

        return [$class, $data];
    }

    private function assertWritableModel(string $class): void
    {
        if (in_array($class, ModelAtlas::WRITE_DENYLIST, true)) {
            throw new McpToolException('Los registros del propio servidor MCP no se gestionan por MCP (usa php artisan mcp:client).');
        }
    }

    private function save(Model $record): void
    {
        try {
            $record->save();
        } catch (QueryException $e) {
            // Nunca reenviar el SQL completo (contiene los valores enlazados).
            $message = strtok($e->getMessage(), '(') ?: 'error de base de datos';
            throw new McpToolException('Error de base de datos: '.trim(mb_substr($message, 0, 200)));
        }
    }

    private function columnName(string $table, string $column): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1 || ! Schema::hasColumn($table, $column)) {
            throw new McpToolException('Columna inválida para '.$table.': '.$column);
        }

        return $column;
    }
}
