<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Enums;

/**
 * Estado MULTIMEDIA de un participante, independiente de su estado en la llamada:
 * aceptar una invitación no es estar conectado a la sala.
 */
enum MediaState: string
{
    /** Pidió entrar a la sala (unirse); aún sin confirmación del servidor de medios. */
    case Connecting = 'connecting';

    /** El servidor de medios confirmó la conexión real (webhook firmado). */
    case Connected = 'connected';

    /** El servidor de medios confirmó la desconexión. */
    case Disconnected = 'disconnected';
}
