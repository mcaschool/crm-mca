<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use Modules\TeamCalls\Contracts\Presence;

/** PROVISIONAL: sin servicio de presencia todavía (CollaborationCore), la capa «en llamada» no se publica. */
final class NoopPresence implements Presence
{
    public function setInCall(int $userId, string $callId, int $ttlSeconds): void {}

    public function clearInCall(int $userId, string $callId): void {}
}
