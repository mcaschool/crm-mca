<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

/**
 * Identidad de un participante en la sala de medios: UNA por persona y dispositivo («u{id}.{device}»).
 * La decide el backend al emitir credenciales; los eventos del servidor de medios se interpretan con
 * parse(). Una identidad que no encaja no pertenece a TeamCalls.
 */
final class MediaIdentity
{
    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
    ) {}

    public static function for(int $userId, string $deviceId): string
    {
        return 'u'.$userId.'.'.$deviceId;
    }

    public static function parse(string $identity): ?self
    {
        if (preg_match('/^u([1-9][0-9]{0,18})\.([A-Za-z0-9._:-]{1,64})$/', $identity, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], $m[2]);
    }

    public function toString(): string
    {
        return self::for($this->userId, $this->deviceId);
    }
}
