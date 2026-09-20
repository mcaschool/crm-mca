<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Modules\Core\Support\SecretMasker;

/**
 * Redacción de secretos en TODO lo que el servidor MCP devuelve o audita: el
 * asistente puede saber que una credencial existe (configured/masked) pero
 * nunca recibe el valor completo. Se aplica por NOMBRE de clave/columna, en
 * profundidad, sobre filas de BD, arrays de config y parámetros auditados.
 */
final class SecretRedactor
{
    /** Claves/columnas cuyo VALOR jamás sale en claro (match por subcadena). */
    private const SECRET_KEYS = [
        'token', 'secret', 'password', 'credential', 'api_key', 'apikey',
        'private_key', 'authorization', 'signature', 'verify', 'client_secret',
        'access_key', 'app_key', 'two_factor', 'remember_token', 'dsn', 'key',
    ];

    /** Claves que aun conteniendo "key" u otras marcas NO son secretos. */
    private const SAFE_KEYS = [
        'foreign_key', 'primary_key', 'keyword', 'keywords', 'cache_key',
        'route_key', 'key_name', 'monkey',
    ];

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SAFE_KEYS as $safe) {
            if (str_contains($key, $safe)) {
                return false;
            }
        }
        foreach (self::SECRET_KEYS as $mark) {
            if (str_contains($key, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacta recursivamente un array (fila, config, params). Un valor bajo
     * clave secreta se sustituye por su forma enmascarada (últimos 4 como
     * máximo, vía SecretMasker) o por un resumen configured/keys si es array.
     */
    public static function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSecretKey($key)) {
                $out[$key] = self::summary($item);

                continue;
            }
            $out[$key] = is_array($item) ? self::redact($item) : $item;
        }

        return $out;
    }

    /** Resumen seguro de un valor secreto: nunca el valor completo. */
    private static function summary(mixed $item): mixed
    {
        if (is_array($item)) {
            // p. ej. credentials cifradas ya decodificadas: solo QUÉ claves hay.
            return [
                'configured' => $item !== [],
                'keys' => array_values(array_filter(array_keys($item), 'is_string')),
            ];
        }
        if (is_string($item) && $item !== '') {
            return ['configured' => true, 'masked' => SecretMasker::mask($item)];
        }
        if (is_bool($item) || $item === null || $item === '') {
            return ['configured' => (bool) $item];
        }

        return ['configured' => true];
    }
}
