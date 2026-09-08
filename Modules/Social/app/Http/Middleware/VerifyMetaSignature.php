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

        // TEMP DEBUG (diagnóstico IG) — QUITAR tras el diagnóstico. Registra que LLEGÓ un POST
        // (antes de validar), para distinguir "no llega nada" (modo desarrollo) de "llega con
        // firma inválida" (app secret equivocado). `secret_source` confirma qué secret se usó.
        // No registra el secreto, solo su origen y si casa la firma.
        $rawHash = $secret !== '' ? hash_hmac('sha256', $request->getContent(), $secret) : '';
        $expected = $rawHash !== '' ? 'sha256='.$rawHash : '';
        $recvHash = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;
        Log::info('social.webhook.debug.signature', [
            'provider' => $provider,
            'has_secret' => $secret !== '',
            'secret_source' => $perProvider !== '' ? "secrets.{$provider}" : ($secret !== '' ? 'app_secret(comun)' : 'ninguno'),
            'has_signature_header' => $header !== '',
            'signature_matches' => $secret !== '' && $header !== '' && hash_equals($expected, $header),
            'body_bytes' => strlen($request->getContent()),
            // TEMP DEBUG (dirigido) — comparar cálculo vs recibido y detectar body/formato.
            'content_type' => $request->header('Content-Type'),
            'calc_prefix' => substr($rawHash, 0, 8),
            'calc_len' => strlen($rawHash),
            'recv_prefix' => substr($recvHash, 0, 8),
            'recv_len' => strlen($recvHash),
            'recv_has_sha256_prefix' => str_starts_with($header, 'sha256='),
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
