<?php

declare(strict_types=1);

namespace Modules\Crm\Support;

use App\Models\User;
use DateTimeZone;
use Modules\Crm\Models\MeetingProfile;
use Throwable;

/**
 * Zona horaria IANA con la que se PRESENTAN fechas a una persona del panel. Las fechas se
 * guardan siempre en UTC; esto solo decide cómo se muestran.
 *
 * Orden: la zona de su perfil de reuniones (la única preferencia horaria por persona que
 * existe, la misma que usa el Calendario) → la zona de su institución → la zona por defecto
 * de la agenda → la de la aplicación. Una zona inválida se ignora y se pasa a la siguiente.
 *
 * En las vistas: `$fecha->userTz()->format(...)` (macro de Carbon registrada en
 * CrmServiceProvider) devuelve una COPIA en la zona de quien mira.
 */
final class UserTimezone
{
    /**
     * Zona de quien hace la petición actual, resuelta una vez por petición (atributo de la
     * Request, no estático: no se arrastra entre peticiones ni entre pruebas).
     */
    public static function current(): string
    {
        $request = request();
        $key = 'crm.user_timezone.'.(string) (auth()->id() ?? 'guest');
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, self::for(auth()->user()));
        }

        return (string) $request->attributes->get($key);
    }

    public static function for(?User $user): string
    {
        $candidates = [];
        if ($user !== null) {
            try {
                $candidates[] = MeetingProfile::query()->where('user_id', $user->getKey())->value('timezone');
            } catch (Throwable) {
                // Sin institución activa (p. ej. fuera del panel): se sigue con las demás zonas.
            }
            $candidates[] = $user->institution?->timezone;
        }
        $candidates[] = config('crm.agenda.default_timezone');
        $candidates[] = config('app.timezone');

        foreach ($candidates as $tz) {
            if (self::isValid($tz)) {
                return $tz;
            }
        }

        return 'UTC';
    }

    public static function isValid(mixed $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
}
