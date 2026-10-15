<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;

/**
 * Cursor OPACO del historial (contrato común CursorPage/PageRequest). Lleva la clave de la última fila y
 * va firmado (HMAC) junto con la persona, la institución y el tipo de listado: un cursor manipulado, de
 * otra persona o de otro listado se rechaza («invalid»), nunca abre filas ajenas. No contiene secretos.
 */
final class HistoryCursor
{
    /** @param array{created_at: string, id: string} $key */
    public static function encode(ActorContext $actor, string $kind, array $key): string
    {
        $payload = self::b64(json_encode(['v' => 1, 'c' => $key['created_at'], 'i' => $key['id']], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::b64(self::mac($actor, $kind, $payload));
    }

    /** @return array{created_at: string, id: string}|null */
    public static function decode(ActorContext $actor, string $kind, ?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || ! hash_equals(self::b64(self::mac($actor, $kind, $parts[0])), $parts[1])) {
            throw new ContractException('invalid', 422);
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_string($data['c'] ?? null) || ! is_string($data['i'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['c']) !== 1 || preg_match('/^[0-9A-Za-z]{26}$/', $data['i']) !== 1) {
            throw new ContractException('invalid', 422);
        }

        return ['created_at' => $data['c'], 'id' => $data['i']];
    }

    private static function mac(ActorContext $actor, string $kind, string $payload): string
    {
        $key = hash_hmac('sha256', 'teamcalls.history-cursor', (string) config('app.key'), true);

        return hash_hmac('sha256', implode('|', [$actor->institutionId, $actor->userId, $kind, $payload]), $key, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
