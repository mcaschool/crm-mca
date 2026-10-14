<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tras cambiar la contraseña, cierra las DEMÁS sesiones de la cuenta (otros navegadores o
 * dispositivos) y rota el token «recordarme», para que quien tuviera una sesión abierta con
 * la clave anterior no la conserve. La sesión actual sigue abierta.
 *
 * Las sesiones viven en base de datos (SESSION_DRIVER=database en producción); con otro
 * controlador solo se rota el token «recordarme».
 */
final class EndOtherSessions
{
    /** @return int sesiones cerradas */
    public static function for(User $user): int
    {
        $closed = 0;
        if (config('session.driver') === 'database') {
            $closed = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', session()->getId())
                ->delete();
        }

        $user->setRememberToken(Str::random(60));
        $user->save();

        return $closed;
    }
}
