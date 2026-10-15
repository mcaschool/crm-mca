<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PanelBadges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicadores del menú lateral en JSON (solo números de los módulos que el usuario puede ver).
 * Lo consulta el sincronizador compartido del panel (partials/panel-badges-sync) desde cualquier
 * pantalla; sin caché para que cada pestaña vea el estado real.
 */
class PanelBadgesController extends Controller
{
    public function __invoke(Request $request, PanelBadges $badges): JsonResponse
    {
        return response()->json((object) $badges->for($request->user()))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
