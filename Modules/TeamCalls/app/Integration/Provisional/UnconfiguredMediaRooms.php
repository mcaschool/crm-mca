<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;

/**
 * Sin servidor de medios configurado (LiveKit llegará con el VPS): pedir credenciales falla de forma
 * explícita y controlada. Retirar participantes o cerrar salas no hace nada (no hay salas reales).
 */
final class UnconfiguredMediaRooms implements MediaRoomService
{
    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials
    {
        throw CallException::mediaUnavailable();
    }

    public function removeParticipant(string $room, string $identity): void {}

    public function closeRoom(string $room): void {}
}
