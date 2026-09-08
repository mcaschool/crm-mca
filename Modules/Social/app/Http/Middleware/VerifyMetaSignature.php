<?php

declare(strict_types=1);

namespace Modules\Social\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 * El secreto se elige por PROVEEDOR (Bloque 3.1): Instagram (IG Login) es una app de Meta
 * APARTE con su propio App Secret y NO debe validarse con el secreto de Facebook. WhatsApp y
 * Messenger sí comparten la app de Facebook, así que para ellos el común (social.app_secret)
 * es un fallback válido. Instagram usa ÚNICAMENTE social.secrets.instagram (sin fallback):
 * si no está cargado, la firma falla de forma ruidosa (401) en vez de usar el secret erróneo.
 */
final class VerifyMetaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');

        // Secret POR PROVEEDOR. Solo WhatsApp/Messenger (misma app de Facebook) caen al común;
        // Instagram jamás usa el secret de Facebook.
        $perProvider = config("social.secrets.{$provider}");
        $perProvider = is_string($perProvider) ? $perProvider : '';
        $common = (string) (config('social.app_secret') ?? '');
        $secret = match ($provider) {
            'instagram' => $perProvider,
            default => $perProvider !== '' ? $perProvider : $common,
        };
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || $header === '') {
            abort(401, 'Firma ausente.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $header)) {
            abort(401, 'Firma inválida.');
        }

        return $next($request);
    }
}
