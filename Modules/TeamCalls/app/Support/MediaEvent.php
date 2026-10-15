<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

/**
 * Evento del servidor de medios YA VERIFICADO, en forma neutral (independiente de LiveKit).
 * $kind: participant_joined | participant_left | room_started | room_finished | (otros: se ignoran).
 */
final class MediaEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $room,
        public readonly ?string $identity = null,
        public readonly ?string $session = null,
    ) {}
}
