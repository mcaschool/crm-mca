<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\TeamCalls\Http\CallResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envuelve la pila del panel (sesión, CSRF, auth, institución, permiso del panel y 2FA obligatorio,
 * SIN cambiarla) para que la API de llamadas responda SIEMPRE en JSON con el formato de
 * CallResponse:
 *
 *  - Fuerza «Accept: application/json» (auth responde 401 en vez de redirigir al login).
 *  - La redirección del 2FA obligatorio a «Mi perfil» se traduce a 403 «two_factor_required».
 *  - Los errores del framework (401, 403, 404, 419, 422, 429, 5xx) se normalizan a
 *    {"error": {code, message}}; en 5xx nunca sale el detalle interno (ni con APP_DEBUG).
 *    Lo que el enrutador rechaza antes de elegir ruta (405, rutas inexistentes) queda fuera de
 *    este middleware y sale con el JSON genérico de Laravel.
 */
final class RespondWithCallsJson
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        419 => 'csrf_token_mismatch',
        422 => 'invalid',
        429 => 'too_many_requests',
    ];

    /** Cabeceras del original que se conservan al normalizar (límites de frecuencia). */
    private const KEEP = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        /** @var Response $response */
        $response = $next($request);

        if ($response->isRedirection()) {
            $normalized = $this->isTwoFactorRedirect($response)
                ? CallResponse::error('two_factor_required', 'Two-factor authentication must be enabled.', 403)
                : CallResponse::error('unauthenticated', 'Unauthenticated.', 401);
        } elseif ($response->getStatusCode() >= 400 && ! $this->isEnvelope($response)) {
            $normalized = $this->normalize($response);
        } else {
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        foreach (self::KEEP as $header) {
            if ($response->headers->has($header)) {
                $normalized->headers->set($header, (string) $response->headers->get($header));
            }
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $normalized->headers->setCookie($cookie);
        }

        return $normalized;
    }

    private function normalize(Response $response): Response
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : [];

        if ($status >= 500) {
            return CallResponse::error($status === 503 ? 'service_unavailable' : 'server_error', 'Server error.', $status);
        }
        $message = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'Request failed.';
        $extra = $status === 422 && is_array($body['errors'] ?? null) ? ['fields' => $body['errors']] : [];

        return CallResponse::error(self::CODES[$status] ?? 'bad_request', $message, $status, $extra);
    }

    private function isEnvelope(Response $response): bool
    {
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && is_array($body['error'] ?? null) && is_string($body['error']['code'] ?? null);
    }

    private function isTwoFactorRedirect(Response $response): bool
    {
        if (! Route::has('profile.me')) {
            return false;
        }
        $target = parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);

        return is_string($target) && $target === parse_url(route('profile.me'), PHP_URL_PATH);
    }
}
