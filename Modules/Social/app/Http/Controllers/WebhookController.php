<?php

declare(strict_types=1);

namespace Modules\Social\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Social\Services\MetaWebhookNormalizer;
use Modules\Social\Services\SocialIngestService;

/**
 * Webhook DIRECTO de Meta (sin intermediario). Dos verbos por proveedor:
 *  - GET  → handshake de verificación (Meta prueba que el endpoint es tuyo).
 *  - POST → recepción de eventos. La firma ya la validó VerifyMetaSignature.
 *
 * Regla de oro: salvo el handshake (que puede dar 403) y la firma (401), TODO POST con
 * firma válida responde 200 — incluso lo que no sabemos procesar — para no gatillar los
 * reintentos en bucle de Meta. El procesamiento es síncrono (volumen actual); si crece,
 * se moverá a una cola sin cambiar este contrato.
 */
final class WebhookController
{
    public function __construct(
        private readonly MetaWebhookNormalizer $normalizer,
        private readonly SocialIngestService $ingest,
    ) {}

    /**
     * Handshake: Meta manda hub.mode / hub.verify_token / hub.challenge (PHP convierte los
     * puntos en guiones bajos en la query). Si el token casa y mode='subscribe', se devuelve
     * el challenge como TEXTO PLANO; si no, 403.
     */
    public function verify(Request $request, string $provider): Response
    {
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');
        $expected = (string) config('social.webhook_verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Verification failed', 403)->header('Content-Type', 'text/plain');
    }

    /**
     * Recepción de eventos. Normaliza el payload crudo y delega: cada mensaje al núcleo de
     * ingesta y cada estado (value.statuses de WhatsApp) al reconciliador por wamid.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $messages = $this->normalizer->normalize($provider, $payload);
        $statuses = $this->normalizer->normalizeStatuses($provider, $payload);

        if ($messages === [] && $statuses === []) {
            // Evento sin nada procesable (echoes IG/Messenger, comentarios/feed, etc.).
            Log::info('social.webhook: evento sin mensajes ingeribles', [
                'provider' => $provider,
                'object' => is_string($payload['object'] ?? null) ? $payload['object'] : null,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $results = [];
        foreach ($messages as $message) {
            $result = $this->ingest->ingest($message);
            $results[] = [
                'status' => $result->status,
                'conversation_id' => $result->conversationId,
                'message_id' => $result->messageId,
            ];
        }

        // Los estados nunca fallan el webhook: wamid desconocido o regresión → se ignoran.
        $statusResults = [];
        foreach ($statuses as $status) {
            $statusResults[] = $this->ingest->applyStatus($status);
        }

        return response()->json(['status' => 'ok', 'results' => $results, 'statuses' => $statusResults], 200);
    }
}
