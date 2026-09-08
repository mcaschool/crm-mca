<?php

declare(strict_types=1);

namespace Modules\Social\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida la firma HMAC de Meta ANTES de procesar cualquier evento (solo POST).
 *
 * Meta firma cada webhook con el App Secret y envía `X-Hub-Signature-256: sha256=<hmac>`.
 * El HMAC se calcula SOBRE EL CUERPO CRUDO tal cual llegó (getContent()), nunca sobre el
 * array re-serializado (cualquier reordenación/espaciado cambiaría el hash). Comparación
 * en tiempo constante con hash_equals. Firma ausente, mal formada o que no casa → 401,
 * sin procesar nada. Sin App Secret configurado → 401 (no se puede verificar → no se confía).
 *
 * El secreto se elige por PROVEEDOR (Bloque 3.1): Instagram Login es una app aparte con su
 * propio App Secret; WhatsApp/Messenger comparten el de la app de Facebook. Se toma
 * social.secrets.{provider} y, si no está, el común social.app_secret.
 */
final class VerifyMetaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');
        $secret = (string) (config("social.secrets.{$provider}") ?? config('social.app_secret') ?? '');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        // TEMP DEBUG (diagnóstico IG) — QUITAR tras el diagnóstico. Registra que LLEGÓ un POST
        // (antes de validar), para distinguir "no llega nada" (modo desarrollo) de "llega con
        // firma inválida" (app secret equivocado). No registra el secreto, solo si casa la firma.
        $expected = $secret !== '' ? 'sha256='.hash_hmac('sha256', $request->getContent(), $secret) : '';
        Log::info('social.webhook.debug.signature', [
            'provider' => $provider,
            'has_secret' => $secret !== '',
            'has_signature_header' => $header !== '',
            'signature_matches' => $secret !== '' && $header !== '' && hash_equals($expected, $header),
            'body_bytes' => strlen($request->getContent()),
        ]);

        if ($secret === '' || $header === '') {
            abort(401, 'Firma ausente.');
        }

        if (! hash_equals($expected, $header)) {
            abort(401, 'Firma inválida.');
        }

        return $next($request);
    }
}
