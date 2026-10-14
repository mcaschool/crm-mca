<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Illuminate\Support\Carbon;
use Modules\Crm\Support\UserTimezone;
use Throwable;

/**
 * Fecha y hora escritas en una zona IANA → instante en UTC para programar una publicación.
 * Rechaza zonas no válidas, horas que no existen en esa zona (el salto del cambio de hora) y
 * momentos que no estén al menos MIN_LEAD_MINUTES en el futuro.
 */
final class ScheduleInput
{
    public const MIN_LEAD_MINUTES = 2;

    /** @return Carbon|string instante en UTC o mensaje de error (ya traducido) */
    public static function toUtc(string $date, string $time, string $tz): Carbon|string
    {
        if (! UserTimezone::isValid($tz)) {
            return __('Elige una zona horaria válida.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return __('Indica la fecha y la hora de publicación.');
        }
        try {
            $local = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $tz);
        } catch (Throwable) {
            $local = null;
        }
        if (! $local instanceof Carbon || $local->format('Y-m-d H:i') !== $date.' '.$time) {
            return __('La fecha u hora no existe en esa zona horaria (p. ej. por el cambio de hora).');
        }
        $utc = $local->copy()->utc();
        if ($utc->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            return __('Elige un momento al menos 2 minutos en el futuro.');
        }

        return $utc;
    }
}
