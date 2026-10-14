<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Integrations\Models\Integration;

/**
 * Conexiones institucionales de videoconferencia (una de Google Meet y una de Zoom por institución),
 * guardadas como `integrations` de tipo google_meet / zoom: credenciales y tokens cifrados en
 * `config`, nunca serializados ni mostrados. El aislamiento lo da el scope de institución.
 *
 * Estados (`integrations.status`): pending (credenciales guardadas, falta consentimiento o prueba) ·
 * active (conectada y verificada) · expired (autorización caducada o revocada) · inactive (desconectada).
 */
class VideoConnections
{
    public const GOOGLE = 'google_meet';

    public const ZOOM = 'zoom';

    public const TYPES = [self::GOOGLE, self::ZOOM];

    public function get(string $type): ?Integration
    {
        return Integration::query()->where('type', $type)->orderBy('id')->first();
    }

    public function getOrNew(string $type): Integration
    {
        $integration = $this->get($type);
        if ($integration === null) {
            $integration = new Integration;
            $integration->type = $type;
            $integration->name = $type === self::GOOGLE ? 'Google Meet' : 'Zoom';
            $integration->status = 'pending';
        }

        return $integration;
    }

    public function isConnected(?Integration $integration): bool
    {
        return $integration !== null && $integration->status === 'active';
    }

    /**
     * Escribe valores en la config cifrada. A diferencia de replaceSecrets(), permite BORRAR claves.
     *
     * @param  array<string,mixed>  $values
     * @param  array<int,string>  $forget
     */
    public function put(Integration $integration, array $values, array $forget = []): void
    {
        $config = $integration->getAttribute('config');
        $config = is_array($config) ? $config : [];
        foreach ($forget as $key) {
            unset($config[$key]);
        }
        $integration->setAttribute('config', array_merge($config, $values));
        $integration->save();
    }

    public function recordTest(Integration $integration, bool $ok, string $message, ?string $status = null): void
    {
        $integration->forceFill(array_filter([
            'last_tested_at' => now(),
            'last_test_ok' => $ok,
            'last_test_message' => Str::limit($message, 250),
            'status' => $status,
        ], fn ($v) => $v !== null))->save();
    }

    /** Fallo del proveedor: estado coherente + registro técnico SIN secretos. */
    public function recordFailure(Integration $integration, VideoProviderException $e, string $operation): void
    {
        Log::warning('video.provider_error', [
            'provider' => $integration->type,
            'institution_id' => $integration->institution_id,
            'operation' => $operation,
            'kind' => $e->kind,
            'detail' => Str::limit($e->getMessage(), 200),
        ]);
        // Un rechazo de Zoom para una reunión concreta (restricción del plan o de la cuenta) no es un
        // fallo de la conexión: se informa junto a la sala, sin marcar la conexión como fallida.
        if ($e->kind !== 'rejected') {
            $this->recordTest($integration, false, $e->userMessage(), $e->kind === 'auth' ? 'expired' : null);
        }
    }

    /** Petición HTTP con tiempo de espera y reintentos SOLO en errores transitorios. */
    public function http(bool $idempotent = true): PendingRequest
    {
        $retries = max(0, (int) config('crm.video.retries', 2));

        return Http::acceptJson()
            ->timeout(max(2, (int) config('crm.video.timeout', 10)))
            ->connectTimeout(5)
            ->retry($retries + 1, 250, function (\Throwable $e) use ($idempotent): bool {
                if ($e instanceof ConnectionException) {
                    return $idempotent; // un POST que no sabemos si llegó no se repite a ciegas
                }
                $status = $e instanceof \Illuminate\Http\Client\RequestException ? $e->response->status() : 0;

                return $status === 429 || ($idempotent && $status >= 500);
            }, throw: false);
    }

    /** Traduce una respuesta fallida a un error controlado (sin copiar su cuerpo). */
    public function failure(Response $response, string $operation): VideoProviderException
    {
        $status = $response->status();
        $code = (string) (data_get($response->json(), 'error.status') ?? data_get($response->json(), 'error') ?? data_get($response->json(), 'code') ?? '');
        $detail = $this->zoomDetail($response, $operation);
        $kind = match (true) {
            $status === 401, $code === 'invalid_grant', $code === '124' => 'auth',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status === 403, $code === 'PERMISSION_DENIED' => 'permission',
            $status === 400 && $code === 'FAILED_PRECONDITION' => 'license',
            $status === 400 && $detail !== null => 'rejected',
            $status >= 500 => 'transport',
            default => 'invalid_response',
        };

        return new VideoProviderException($kind, $operation.': HTTP '.$status.($code !== '' && preg_match('/^[A-Za-z0-9_]{1,40}$/', $code) ? ' '.$code : ''), $detail);
    }

    /**
     * Motivo que devuelve la API de Zoom (restricciones del plan o de la cuenta, límites diarios…)
     * para mostrarlo tal cual a la administración: solo el texto, sin enlaces ni etiquetas, acotado.
     * El endpoint del token no aporta detalle (podría reflejar datos de las credenciales).
     */
    private function zoomDetail(Response $response, string $operation): ?string
    {
        if (! str_starts_with($operation, 'zoom.') || $operation === 'zoom.token' || $operation === 'zoom.revoke') {
            return null;
        }
        $message = $response->json('message');
        if (! is_string($message)) {
            return null;
        }
        // Fuera enlaces, parámetros con credenciales (zak, token, pwd…) y cadenas con aspecto de token.
        $clean = trim((string) preg_replace(
            ['~https?://\S+~i', '/\b(zak|token|access_token|refresh_token|pwd|password|passcode|key|secret)\s*[=:]\s*\S+/i', '/[A-Za-z0-9._~+\/-]{24,}={0,2}/', '/[\x00-\x1F\x7F]+/u', '/\s+/u'],
            ['', '[…]', '[…]', ' ', ' '],
            strip_tags($message),
        ));

        return $clean === '' ? null : Str::limit($clean, 200);
    }

    /**
     * Ejecuta la petición traduciendo errores de red a VideoProviderException.
     *
     * @param  callable(): Response  $send
     */
    public function send(callable $send, string $operation): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new VideoProviderException('transport', $operation.': sin respuesta');
        }
    }
}
