<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Compatibilidad del nombre antiguo del widget: /widget/celia.js sirve EXACTAMENTE el mismo
 * archivo que /widget/chat-widget.js (única fuente de código, sin copias que diverjan). Lo
 * necesitan las webs donde ya está incrustado con el nombre antiguo; los snippets nuevos
 * usan chat-widget.js. El script se localiza a sí mismo por data-bot-key, con cualquiera
 * de los dos nombres.
 */
class WidgetScriptController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(public_path('widget/chat-widget.js'), [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
