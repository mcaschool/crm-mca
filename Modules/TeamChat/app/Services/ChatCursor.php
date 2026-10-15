<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Illuminate\Support\Facades\Crypt;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;

final class ChatCursor
{
    /** @param array<string,int|string> $position */
    public function encode(ActorContext $actor, string $purpose, array $position): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'user' => $actor->userId, 'institution' => $actor->institutionId,
            'purpose' => $purpose, 'expires' => now()->addDay()->timestamp, 'position' => $position], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,int|string> */
    public function decode(ActorContext $actor, string $purpose, string $cursor): array
    {
        try {
            $data = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
            if (($data['v'] ?? null) !== 1 || ($data['user'] ?? null) !== $actor->userId || ($data['institution'] ?? null) !== $actor->institutionId
                || ($data['purpose'] ?? null) !== $purpose || ! is_int($data['expires'] ?? null) || ! is_array($data['position'] ?? null)) {
                throw new \UnexpectedValueException;
            }
        } catch (\Throwable) {
            throw new ContractException('invalid', 422);
        }
        if ($data['expires'] <= now()->timestamp) {
            throw new ContractException('sync_cursor_expired', 410);
        }

        return $data['position'];
    }
}
