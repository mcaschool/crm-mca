<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;

/**
 * Abstracción del servidor de medios (LiveKit autohospedado en una fase posterior). TeamCalls decide
 * quién entra y con qué permisos; esta interfaz solo materializa esa decisión. Las credenciales
 * caducan pronto y no se asumen de un solo uso: el backend puede expulsar o cerrar la sala.
 */
interface MediaRoomService
{
    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials;

    public function removeParticipant(string $room, string $identity): void;

    public function closeRoom(string $room): void;
}
