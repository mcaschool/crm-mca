<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * Enmascara secretos para mostrarlos SIN revelarlos (p. ej. `sk-••••8Jk2`).
 *
 * Regla: primeros 3 caracteres + bullets + ultimos 4.
 * Si el valor tiene menos de 8 caracteres, se enmascara ENTERO, para no
 * filtrar secretos cortos.
 */
final class SecretMasker
{
    private const BULLETS = '••••';

    /** Claves cuyo valor se considera secreto y por tanto se enmascara. */
    private const SECRET_KEY_PATTERN = '/(key|secret|token|password|passwd|authorization|auth|credential|client_secret|private)/i';

    public static function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length < 8) {
            return self::BULLETS;
        }

        return mb_substr($value, 0, 3).self::BULLETS.mb_substr($value, -4);
    }

    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match(self::SECRET_KEY_PATTERN, $key);
    }

    /**
     * Devuelve una copia del arreglo de configuracion segura para mostrar:
     * los valores de claves secretas se enmascaran; los metadatos (base_url,
     * region, from_name...) se muestran en claro.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public static function maskConfig(array $config): array
    {
        $preview = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $preview[$key] = self::maskConfig($value);

                continue;
            }

            if (is_string($value) && self::isSecretKey((string) $key)) {
                $preview[$key] = self::mask($value);

                continue;
            }

            // Metadato no sensible: se muestra tal cual (nunca un secreto por la
            // regla de nombres; aun asi, solo escalares).
            $preview[$key] = is_scalar($value) ? $value : null;
        }

        return $preview;
    }
}
