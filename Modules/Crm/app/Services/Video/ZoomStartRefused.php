<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use RuntimeException;

/**
 * El CRM no inicia la reunión como anfitrión. El mensaje ya está traducido y es seguro para el
 * usuario: nunca contiene enlaces de inicio, tokens ni respuestas del proveedor sin sanear.
 */
final class ZoomStartRefused extends RuntimeException {}
