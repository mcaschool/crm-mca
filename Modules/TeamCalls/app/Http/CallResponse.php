<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http;

use Illuminate\Http\JsonResponse;
use Modules\TeamCalls\Support\CallSummary;

/**
 * Formato ÚNICO de las respuestas JSON de la API de llamadas:
 *
 *   éxito  → {"data": {...}, "meta": {"replayed": bool}}       200 (201 al crear una llamada nueva)
 *   error  → {"error": {"code": "...", "message": "..."}}      4xx/5xx según el código
 *
 * «replayed: true» indica una operación repetida que no cambió nada (reintento idempotente). Los
 * mensajes son técnicos, en inglés y sin datos sensibles; el cliente decide por «code».
 */
final class CallResponse
{
    public static function call(CallSummary $call, bool $replayed, int $status = 200): JsonResponse
    {
        return self::json(['data' => $call->toArray(), 'meta' => ['replayed' => $replayed]], $status)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    /** @param list<CallSummary> $calls */
    public static function calls(array $calls): JsonResponse
    {
        return self::json([
            'data' => array_map(fn (CallSummary $c) => $c->toArray(), $calls),
            'meta' => ['count' => count($calls)],
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function data(array $data): JsonResponse
    {
        return self::json(['data' => $data]);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return self::json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'no-store, private']);
    }
}
