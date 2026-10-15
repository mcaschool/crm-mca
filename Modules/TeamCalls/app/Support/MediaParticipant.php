<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Illuminate\Support\Carbon;

/** Participante REAL de una sala según el servidor de medios (no según la base de datos). */
final class MediaParticipant
{
    public function __construct(
        public readonly string $identity,
        public readonly string $session,
        public readonly string $state,
        public readonly ?Carbon $joinedAt,
        /** true si el backend ya le concedió medios (puede suscribirse) */
        public readonly bool $admitted = false,
    ) {}
}
