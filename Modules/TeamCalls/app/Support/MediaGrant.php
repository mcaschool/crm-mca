<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

/**
 * Lo que el BACKEND autoriza a un participante en la sala de medios: la sala, una identidad por
 * persona y dispositivo y sus permisos. El servidor de medios solo ejecuta esta decisión.
 */
final class MediaGrant
{
    public function __construct(
        public readonly string $room,
        public readonly string $identity,
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly bool $canPublishVideo,
        public readonly bool $canPublishAudio,
        public readonly bool $canShareScreen,
        public readonly bool $isHost,
        public readonly int $ttlSeconds,
    ) {}
}
