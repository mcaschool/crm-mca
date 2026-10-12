<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use RuntimeException;

/**
 * El hueco elegido está fuera de disponibilidad o se solapa con otra actividad y no se
 * confirmó la excepción manual: NO se guarda nada.
 */
final class ScheduleConflictException extends RuntimeException
{
    public function __construct(public readonly AvailabilityCheck $check)
    {
        parent::__construct('Horario no disponible: '.implode(' ', $check->issues));
    }
}
