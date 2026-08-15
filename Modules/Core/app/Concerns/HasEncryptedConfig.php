<?php

declare(strict_types=1);

namespace Modules\Core\Concerns;

use Modules\Core\Support\SecretMasker;

/**
 * Base de gestion de secretos para modelos que guardan credenciales de terceros
 * (p. ej. integrations.config). Implementa varias de las "siete barreras":
 *
 *  1. Cifrado en reposo: `config` se castea a `encrypted:array` (AES-256-GCM
 *     autenticado, derivado de APP_KEY). El resto del codigo nunca ve texto cifrado.
 *  2. Ocultamiento: `config` va en $hidden -> jamas se serializa a JSON/array.
 *  3. Enmascarado: al guardar se recalcula `config_preview` (columna SIN cifrar,
 *     porque no contiene secreto) que el panel muestra en vez de `config`.
 *
 * Requisitos en el modelo que use el trait:
 *  - columnas `config` (text) y `config_preview` (json) en su migracion,
 *  - incluir 'config' en $hidden,
 *  - castear 'config' => 'encrypted:array' y 'config_preview' => 'array'.
 * Este trait aporta el recalculo automatico de config_preview y accesos seguros.
 */
trait HasEncryptedConfig
{
    public static function bootHasEncryptedConfig(): void
    {
        static::saving(function ($model): void {
            // Si config cambio (o es nuevo), recalcula el preview enmascarado.
            if ($model->isDirty('config')) {
                $config = $model->getAttribute('config') ?? [];
                $model->setAttribute(
                    'config_preview',
                    SecretMasker::maskConfig(is_array($config) ? $config : [])
                );
            }
        });
    }

    /**
     * Config enmascarada, segura para mostrar en el panel. NUNCA devuelve secretos.
     *
     * @return array<string,mixed>
     */
    public function maskedConfig(): array
    {
        $preview = $this->getAttribute('config_preview');

        if (is_array($preview)) {
            return $preview;
        }

        $config = $this->getAttribute('config');

        return SecretMasker::maskConfig(is_array($config) ? $config : []);
    }

    /**
     * Lee un secreto en claro. Punto de acceso UNICO y explicito, pensado para
     * usarse solo en el servidor, dentro del cliente que llama al proveedor.
     * No existe endpoint ni vista que exponga esto.
     */
    public function secret(string $key, mixed $default = null): mixed
    {
        $config = $this->getAttribute('config');

        return is_array($config) ? ($config[$key] ?? $default) : $default;
    }

    /**
     * Reemplaza una credencial conservando el resto de la config. Guardar con un
     * valor vacio NO borra la credencial existente (se conserva la actual).
     *
     * @param  array<string,mixed>  $newValues
     */
    public function replaceSecrets(array $newValues): void
    {
        $config = $this->getAttribute('config');
        $config = is_array($config) ? $config : [];

        foreach ($newValues as $key => $value) {
            if ($value === null || $value === '') {
                // Campo vacio en el formulario = conservar el actual.
                continue;
            }

            $config[$key] = $value;
        }

        $this->setAttribute('config', $config);
    }
}
