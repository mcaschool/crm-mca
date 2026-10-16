<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Http\Middleware\CollaborationRequest;
use Modules\TeamCalls\Http\CallResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contexto autenticado de CollaborationCore para las rutas web de TeamCalls (API web y página).
 *
 * Reutiliza el middleware de Core (`CollaborationRequest`): ActorContextFactory valida la sesión, el
 * segundo factor (prueba MFA de la sesión), collaboration.use y la institución, registra/valida el
 * DISPOSITIVO de esa sesión en DeviceRegistry y ejecuta la petición en la institución del actor. El
 * actor queda en el atributo `collaboration.actor`; ningún identificador del cliente interviene.
 *
 * Solo traduce los errores del contrato al formato de TeamCalls (JSON) o a su estado HTTP (página),
 * porque el renderizador de Core solo cubre /api/v1/collaboration.
 */
final class RequireCollaborationActor
{
    public function __construct(private readonly CollaborationRequest $core) {}

    public function handle(Request $request, Closure $next, string $mode = 'json'): Response
    {
        try {
            return $this->core->handle($request, $next);
        } catch (ContractException $e) {
            if ($mode === 'page') {
                abort($e->httpStatus);
            }

            return CallResponse::error($e->errorCode, $e->errorCode, $e->httpStatus);
        }
    }
}
