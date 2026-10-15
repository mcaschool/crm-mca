<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TeamCalls\Http\CallResponse;
use Modules\TeamCalls\Integration\LiveKit\InvalidMediaWebhook;
use Modules\TeamCalls\Integration\LiveKit\LiveKitConfig;
use Modules\TeamCalls\Integration\LiveKit\LiveKitTokens;
use Modules\TeamCalls\Integration\LiveKit\LiveKitWebhookEvent;
use Modules\TeamCalls\Services\MediaEventIngestor;

/**
 * Webhooks de LiveKit (sin sesión ni CSRF: los autentica su firma). Se verifica la firma oficial sobre
 * el cuerpo ORIGINAL antes de interpretar nada; sin LiveKit configurado la ruta no existe (404).
 *
 * Respuestas: 200 {data: {outcome}} (aplicado, ignorado o rechazado: no hace falta reintentar),
 * 401 invalid_signature, 400 bad_request, 413 payload_too_large. Un fallo interno da 500 y LiveKit
 * puede reintentar: los eventos son idempotentes por su id.
 */
final class LiveKitWebhookController
{
    private const MAX_BYTES = 65536;

    public function __invoke(Request $request, MediaEventIngestor $ingestor): JsonResponse
    {
        $config = LiveKitConfig::fromConfig();
        if (config('teamcalls.media.driver') !== 'livekit' || ! $config->isComplete()) {
            return CallResponse::error('not_found', 'Not found.', 404);
        }
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BYTES) {
            return CallResponse::error('payload_too_large', 'Payload too large.', 413);
        }
        try {
            (new LiveKitTokens($config))->verifyWebhook($request->header('Authorization'), $raw);
        } catch (InvalidMediaWebhook) {
            return CallResponse::error('invalid_signature', 'Invalid webhook signature.', 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_string($payload['event'] ?? null)) {
            return CallResponse::error('bad_request', 'Malformed event.', 400);
        }
        $event = LiveKitWebhookEvent::toMediaEvent($payload);

        return CallResponse::data(['outcome' => $event === null ? 'ignored' : $ingestor->ingest($event)]);
    }
}
