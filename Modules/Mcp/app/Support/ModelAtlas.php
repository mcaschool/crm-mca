<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Descubrimiento dinámico de modelos Eloquent de TODO el CRM (app/Models +
 * Modules/&#42;/app/Models), sin registros manuales: los módulos futuros aparecen
 * solos. Los modelos se referencian como "Modulo.Clase" (p. ej. Crm.Lead,
 * Social.SocialChannel) o por su nombre corto cuando es único.
 */
final class ModelAtlas
{
    /** Modelos de infraestructura del propio MCP: jamás escribibles vía MCP. */
    public const WRITE_DENYLIST = [
        \Modules\Mcp\Models\McpClient::class,
        \Modules\Mcp\Models\McpAuditLog::class,
    ];

    /** @var array<string, class-string<Model>>|null clave "Modulo.Clase" => FQCN */
    private static ?array $map = null;

    /**
     * @return array<string, class-string<Model>>
     */
    public static function all(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];
        foreach (glob(base_path('app/Models/*.php')) ?: [] as $file) {
            self::register($map, 'App', 'App\\Models\\'.basename($file, '.php'));
        }
        foreach (glob(base_path('Modules/*/app/Models/*.php')) ?: [] as $file) {
            $module = basename(dirname($file, 3));
            self::register($map, $module, 'Modules\\'.$module.'\\Models\\'.basename($file, '.php'));
        }
        ksort($map);

        return self::$map = $map;
    }

    /**
     * Resuelve "Crm.Lead", "Lead" (si es único) o un FQCN. Lanza McpToolException
     * con las opciones disponibles si no existe o es ambiguo.
     *
     * @return class-string<Model>
     */
    public static function resolve(string $name): string
    {
        $map = self::all();
        if (isset($map[$name])) {
            return $map[$name];
        }
        if (class_exists($name) && in_array($name, $map, true)) {
            /** @var class-string<Model> $name */
            return $name;
        }

        $matches = [];
        foreach ($map as $key => $class) {
            if (str_ends_with($key, '.'.$name)) {
                $matches[$key] = $class;
            }
        }
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }
        if (count($matches) > 1) {
            throw new McpToolException('Modelo ambiguo "'.$name.'": usa uno de ['.implode(', ', array_keys($matches)).'].');
        }

        throw new McpToolException('Modelo desconocido "'.$name.'". Consulta crm_models para ver los disponibles.');
    }

    /** ¿El modelo lleva el scope multi-institución (trait BelongsToInstitution)? */
    public static function isInstitutionScoped(string $class): bool
    {
        return in_array(BelongsToInstitution::class, class_uses_recursive($class), true);
    }

    /**
     * Métodos de relación Eloquent detectados por reflexión (tipo de retorno).
     *
     * @param  class-string<Model>  $class
     * @return array<int, array{name: string, type: string}>
     */
    public static function relations(string $class): array
    {
        $out = [];
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            if ($method->class !== $class || $method->getNumberOfParameters() > 0) {
                continue;
            }
            $return = $method->getReturnType();
            if ($return instanceof ReflectionNamedType && ! $return->isBuiltin()
                && is_subclass_of($return->getName(), Relation::class)) {
                $out[] = ['name' => $method->getName(), 'type' => class_basename($return->getName())];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, class-string<Model>>  $map
     */
    private static function register(array &$map, string $module, string $class): void
    {
        if (! class_exists($class)) {
            return;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            return;
        }
        /** @var class-string<Model> $class */
        $map[$module.'.'.$reflection->getShortName()] = $class;
    }
}
