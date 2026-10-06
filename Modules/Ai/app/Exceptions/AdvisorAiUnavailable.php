<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use RuntimeException;

/**
 * El proveedor de IA falló de forma pasajera y el canal pidió REINTENTAR en lugar de contestar
 * con el mensaje de «no disponible» (canales sociales con despacho persistente). Se lanza antes
 * de registrar ninguna respuesta, así el reintento no deja mensajes ni eventos duplicados.
 */
final class AdvisorAiUnavailable extends RuntimeException {}
