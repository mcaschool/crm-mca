<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Crm\Services\Video\VideoRoomService;
use Modules\Crm\Services\Video\ZoomStartRefused;

/**
 * «Iniciar mi reunión como anfitrión»: POST del propio usuario autenticado (con CSRF y límite de
 * frecuencia). No recibe ningún identificador: siempre es la sala de quien lo pide, así que nadie
 * puede pedir el enlace de anfitrión de otra persona cambiando un id.
 *
 * El enlace de inicio de Zoom es una credencial temporal: se pide a Zoom en este momento, se valida
 * (dominio y reunión de Zoom) y viaja SOLO en la cabecera Location de una redirección 303 sin caché
 * ni Referer. No se guarda, no se registra, no se audita y nunca aparece en el HTML del CRM.
 */
class ZoomHostStartController extends Controller
{
    private const NO_STORE = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
        'Pragma' => 'no-cache',
        'Expires' => '0',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __invoke(Request $request, VideoRoomService $rooms): RedirectResponse
    {
        try {
            $url = $rooms->startUrlFor($request->user());
        } catch (ZoomStartRefused $e) {
            return redirect()->route('crm.agenda.profile')
                ->with('zoom_start_error', $e->getMessage())
                ->withHeaders(self::NO_STORE);
        }

        return redirect()->away($url, 303)->withHeaders(self::NO_STORE);
    }
}
