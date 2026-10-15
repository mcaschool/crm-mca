<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;

/**
 * Sin servidor de medios configurado (LiveKit llegará con el VPS): preparar salas y pedir
 * credenciales falla de forma explícita y controlada (503 «media_unavailable»). Retirar
 * participantes o cerrar salas no hace nada (no hay salas reales).
 */
final class UnconfiguredMediaRooms implements MediaRoomService
{
    public function available(): bool
    {
        return false;
    }

    public function confirmsConnections(): bool
    {
        return true;
    }

    public function prepareRoom(string $room, int $maxParticipants): void
    {
        throw CallException::mediaUnavailable();
    }

    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials
    {
        throw CallException::mediaUnavailable();
    }

    public function admitParticipant(MediaGrant $grant): void {}

    public function participants(string $room): array
    {
        return [];
    }

    public function rooms(): array
    {
        return [];
    }

    public function removeParticipant(string $room, string $identity): void {}

    public function closeRoom(string $room): void {}
}
