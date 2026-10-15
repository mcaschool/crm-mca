<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

/**
 * PUERTO hacia la presencia compartida (CollaborationCore). TeamCalls solo añade o retira la capa
 * temporal «en llamada»; nunca toca el estado manual de la persona.
 */
interface Presence
{
    public function setInCall(int $userId, string $callId, int $ttlSeconds): void;

    public function clearInCall(int $userId, string $callId): void;
}
