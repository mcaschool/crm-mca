<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve el widget embebible: /widget/chat-widget.js (nombre actual, el de los snippets) y
 * /widget/celia.js (nombre antiguo, aún incrustado en webs) devuelven EXACTAMENTE el mismo
 * archivo, resources/widget/chat-widget.js (única fuente de código, sin copias que diverjan).
 * El script se localiza a sí mismo por data-bot-key, con cualquiera de los dos nombres.
 *
 * Caché: corta (MAX_AGE) y con validadores (ETag/Last-Modified → 304), para que tras un
 * redeploy navegadores y CDN obtengan la versión nueva en minutos aunque el snippet ya pegado
 * en la web lleve un ?v= fijo. Antes, como archivo estático, la CDN lo servía con 7 días.
 */
class WidgetScriptController extends Controller
{
    /** Segundos de caché del script en navegador/CDN antes de revalidar. */
    public const MAX_AGE = 300;

    public static function path(): string
    {
        return resource_path('widget/chat-widget.js');
    }

    public function __invoke(Request $request): BinaryFileResponse
    {
        $response = response()->file(self::path(), [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::MAX_AGE.', must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setAutoEtag();
        $response->isNotModified($request); // 304 sin cuerpo si el cliente ya tiene esta versión

        return $response;
    }
}
