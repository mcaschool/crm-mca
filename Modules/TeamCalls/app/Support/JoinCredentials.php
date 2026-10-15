<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Illuminate\Support\Carbon;

/**
 * Credenciales de acceso a la sala de medios para UN dispositivo. Caducan pronto y NO se asumen de
 * un solo uso: el control real lo mantiene el backend (puede expulsar o cerrar la sala).
 */
final class JoinCredentials
{
    public function __construct(
        public readonly string $serverUrl,
        public readonly string $token,
        public readonly string $identity,
        public readonly Carbon $expiresAt,
    ) {}
}
