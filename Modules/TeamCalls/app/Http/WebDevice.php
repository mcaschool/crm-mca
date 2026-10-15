<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Identificador de dispositivo de la API WEB, decidido por el SERVIDOR y ligado a la sesión.
 *
 * PROVISIONAL hasta CollaborationCore: mientras no exista un registro de dispositivos que permita
 * validarlos, ningún identificador que envíe el cliente se considera confiable (las peticiones que
 * traen «device_id» se rechazan). Cada sesión del panel es un dispositivo; las pestañas de un mismo
 * navegador comparten sesión y, por tanto, dispositivo. Cerrar sesión genera uno nuevo.
 */
final class WebDevice
{
    public const SESSION_KEY = 'teamcalls.device_id';

    public function id(Request $request): string
    {
        $session = $request->session();
        $id = $session->get(self::SESSION_KEY);
        if (! is_string($id) || preg_match('/^web\.[a-z0-9]{24}$/', $id) !== 1) {
            $id = 'web.'.Str::lower(Str::random(24));
            $session->put(self::SESSION_KEY, $id);
        }

        return $id;
    }
}
