<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use RuntimeException;

/** El horario elegido ya no está disponible (lo acaba de reservar otra persona o cambió la disponibilidad). */
final class SlotUnavailableException extends RuntimeException {}
