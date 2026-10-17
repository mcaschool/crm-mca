<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

/**
 * Servidor de medios que sabe que está CAÍDO ahora mismo (fase 4I: disyuntor de LiveKit). Se distingue de
 * «no configurado»: sin configurar, las llamadas se pueden iniciar y unirse da 503 (comportamiento previo);
 * con una caída conocida, no se inicia ninguna llamada que sonaría sin poder conectarse.
 */
interface MediaOutage
{
    public function outage(): bool;
}
