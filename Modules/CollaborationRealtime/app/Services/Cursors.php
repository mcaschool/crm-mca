<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\Crypt;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;

final class Cursors
{
    /** @param array<string, int|string> $state */
    public function encode(ActorContext $actor, array $state): string
    {
        return Crypt::encryptString(json_encode($state + ['v' => 1, 'user' => $actor->userId, 'tenant' => $actor->institutionId, 'expires' => now()->addDay()->timestamp], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int|string> */
    public function decode(ActorContext $actor, string $cursor, string $mode): array
    {
        try {
            $state = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ($state['v'] ?? null) !== 1 || ($state['user'] ?? null) !== $actor->userId || ($state['tenant'] ?? null) !== $actor->institutionId || ($state['mode'] ?? null) !== $mode) {
                throw new \UnexpectedValueException;
            }
            foreach (['expires', 'position', 'cut', 'floor'] as $key) {
                if (! isset($state[$key]) || ! is_int($state[$key]) || $state[$key] < 0) {
                    throw new \UnexpectedValueException;
                }
            }
        } catch (\Throwable) {
            throw new ContractException('invalid', 422);
        }
        if ($state['expires'] <= now()->timestamp) {
            throw new ContractException('sync_cursor_expired', 410);
        }

        return $state;
    }
}
