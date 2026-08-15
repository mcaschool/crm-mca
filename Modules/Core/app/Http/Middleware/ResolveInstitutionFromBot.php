<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Core\Tenancy\Scopes\InstitutionScope;
use Modules\Institutions\Models\Bot;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establece el contexto de institucion en las rutas PUBLICAS del widget, a
 * partir de la llave opaca del bot (`bots.public_key`). El widget NUNCA envia
 * institution_id: lo deduce el servidor. Un cliente no puede pedir datos de
 * otra institucion porque no hay nada que manipular.
 *
 * Este es el arranque (bootstrap) que ESTABLECE el contexto: la busqueda del bot
 * ocurre por fuerza sin contexto todavia, asi que se salta el scope global de
 * forma auditada. Es codigo de Core: la prohibicion de withoutGlobalScope aplica
 * FUERA de Core, no aqui.
 */
final class ResolveInstitutionFromBot
{
    public function __construct(private readonly CurrentInstitution $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = (string) ($request->header('X-Bot-Key') ?? $request->input('bot_key', ''));

        if ($publicKey === '') {
            abort(400, 'Falta la llave del bot.');
        }

        // Bootstrap auditado: sin contexto aun, se resuelve el bot en modo global.
        $bot = $this->context->runGlobally(
            fn () => Bot::query()
                ->withoutGlobalScope(InstitutionScope::class)
                ->where('public_key', $publicKey)
                ->where('status', 'active')
                ->first()
        );

        if ($bot === null) {
            abort(404, 'Bot no encontrado.');
        }

        $this->context->set((int) $bot->institution_id);

        // Se comparte el bot resuelto para que el controlador no lo busque de nuevo.
        $request->attributes->set('bot', $bot);

        return $next($request);
    }
}
