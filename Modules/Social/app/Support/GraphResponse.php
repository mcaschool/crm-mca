<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Illuminate\Http\Client\Response;

/**
 * Lectura ESTRICTA de respuestas de la Graph API de Meta, por código y subcódigo de error (nunca
 * por el texto, que Meta traduce y cambia). Sin estado y sin red: solo interpreta.
 */
final class GraphResponse
{
    /** Objeto inexistente: GraphMethodException #100, subcódigo 33 («Object with ID … does not exist»). */
    private const NOT_FOUND = ['code' => 100, 'subcode' => 33];

    /** Límites de peticiones de Graph (aplicación, usuario, Página, API de negocio). */
    private const RATE_LIMIT_CODES = [4, 17, 32, 341, 368, 613, 80001, 80004, 80005, 80006, 80008, 80014];

    /**
     * ¿La respuesta a un DELETE CONFIRMA el borrado? Solo un 2xx con el cuerpo oficial de éxito:
     * `{"success": true}` (o el `true` desnudo de versiones antiguas de Graph). Un cuerpo vacío,
     * `success: false`, otra forma o un error NO confirman nada.
     */
    public static function confirmsDeletion(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }
        $body = json_decode(trim($response->body()), true);

        return $body === true || (is_array($body) && ($body['success'] ?? null) === true);
    }

    /**
     * Clase de un error de Graph: not_found | token | permission | rate_limit | platform | graph | unreadable.
     * not_found SOLO para el par exacto #100/33 con un estado 4xx: cualquier otra cosa no se toma
     * por «no existe».
     */
    public static function errorKind(Response $response): string
    {
        $status = $response->status();
        $error = self::error($response);
        if ($error === null) {
            return $status >= 500 ? 'platform' : 'unreadable';
        }
        ['code' => $code, 'subcode' => $subcode] = $error;

        return match (true) {
            $code === self::NOT_FOUND['code'] && $subcode === self::NOT_FOUND['subcode'] && $status >= 400 && $status < 500 => 'not_found',
            $code === 190 || $code === 102 || $status === 401 => 'token',
            $code === 10 || $code === 3 || ($code >= 200 && $code <= 299) || $status === 403 => 'permission',
            in_array($code, self::RATE_LIMIT_CODES, true) || $status === 429 => 'rate_limit',
            $code === 1 || $code === 2 || $status >= 500 => 'platform',
            default => 'graph',
        };
    }

    /**
     * Datos SEGUROS para registrar: estado HTTP y códigos de Graph (nunca el mensaje, que puede
     * repetir identificadores, ni el token).
     *
     * @return array{status: int, code: int|null, subcode: int|null, type: string|null}
     */
    public static function summary(Response $response): array
    {
        $error = self::error($response);

        return [
            'status' => $response->status(),
            'code' => $error['code'] ?? null,
            'subcode' => $error['subcode'] ?? null,
            'type' => $error['type'] ?? null,
        ];
    }

    /** @return array{code: int, subcode: int|null, type: string|null}|null */
    private static function error(Response $response): ?array
    {
        $body = json_decode(trim($response->body()), true);
        if (! is_array($body) || ! is_array($body['error'] ?? null) || ! is_numeric($body['error']['code'] ?? null)) {
            return null;
        }
        $e = $body['error'];

        return [
            'code' => (int) $e['code'],
            'subcode' => is_numeric($e['error_subcode'] ?? null) ? (int) $e['error_subcode'] : null,
            'type' => is_string($e['type'] ?? null) ? mb_substr($e['type'], 0, 60) : null,
        ];
    }
}
