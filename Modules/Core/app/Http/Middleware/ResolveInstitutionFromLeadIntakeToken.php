<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Core\Tenancy\Scopes\InstitutionScope;
use Modules\Integrations\Models\Integration;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica y da contexto al endpoint público GENERAL de captación de leads (n8n → CRM,
 * POST /api/v1/leads/intake). Mismo patrón seguro que ResolveInstitutionFromIncompanyToken,
 * pero con su propio secreto `lead_intake_token` (independiente del InCompany):
 *
 *  - El token viaja SOLO en el header `Authorization: Bearer <token>`, nunca por query
 *    string ni dentro del JSON.
 *  - Se compara en tiempo constante (`hash_equals`) contra el token guardado (cifrado) en
 *    la integración `n8n` ACTIVA de cada institución; el token que casa RESUELVE la
 *    institución y fija su contexto.
 *  - Sin token → 401. Token inválido / integración inactiva → 401.
 *  - No registra el token en ningún sitio.
 *
 * El token solo habilita este endpoint: no es sesión de panel ni da acceso a nada más del
 * CRM. Se gestiona/rota desde Ajustes → Integraciones → n8n.
 *
 * Es el arranque (bootstrap) que ESTABLECE el contexto: la búsqueda de la integración
 * ocurre por fuerza sin contexto todavía, así que se salta el scope global de forma
 * auditada. Es código de Core: la prohibición de withoutGlobalScope aplica FUERA de Core.
 */
final class ResolveInstitutionFromLeadIntakeToken
{
    public function __construct(private readonly CurrentInstitution $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) ($request->bearerToken() ?? '');
        if ($bearer === '') {
            abort(401, 'Falta el token de autenticación.');
        }

        // Bootstrap auditado: sin contexto aún, se comparan TODAS las integraciones n8n
        // activas en modo global y en tiempo constante; el token que casa da la institución.
        // En modo una-institución por instalación hay una sola.
        $institutionId = $this->context->runGlobally(function () use ($bearer): ?int {
            $integrations = Integration::query()
                ->withoutGlobalScope(InstitutionScope::class)
                ->where('type', 'n8n')
                ->where('status', 'active')
                ->get();

            foreach ($integrations as $integration) {
                $token = $integration->secret('lead_intake_token');
                if (is_string($token) && $token !== '' && hash_equals($token, $bearer)) {
                    return (int) $integration->institution_id;
                }
            }

            return null;
        });

        if ($institutionId === null) {
            abort(401, 'Token inválido.');
        }

        $this->context->set($institutionId);

        return $next($request);
    }
}
