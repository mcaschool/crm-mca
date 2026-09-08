<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Throwable;

/**
 * Envía un mensaje de texto a Meta (SALIDA, Bloque 4) para Instagram + Messenger. Endpoints
 * confirmados en la doc vigente de Meta:
 *  - Messenger: POST https://graph.facebook.com/{version}/me/messages
 *      body {recipient:{id:PSID}, messaging_type:'RESPONSE', message:{text}}  (Page Access Token)
 *  - Instagram (cuenta vinculada a una Página de Facebook, Page Access Token):
 *      POST https://graph.facebook.com/{version}/me/messages
 *      body {recipient:{id:IGSID}, message:{text}}
 *      El nodo es 'me': la Página queda determinada por el propio Page Access Token, NO se usa
 *      el Page ID ni el IG User ID como nodo de la URL (usar {page_id} devuelve (#12) "singular
 *      links API is deprecated"; usar el IG User ID devuelve code 100 / subcode 33).
 *      Token EAA → graph.facebook.com (graph.instagram.com espera IGAA → 190 "Cannot parse").
 * El token viaja en el header (Bearer), nunca en la URL; el texto va en el cuerpo. La versión
 * de Graph es configurable (social.graph_version). WhatsApp NO se envía aquí.
 *
 * Ventana de 24h: fuera de ella Meta responde 400 con error.code=10 / error_subcode=2018278
 * ("message sent outside of allowed window"); eso se traduce a 'failed_window'.
 */
final class MetaMessageSender
{
    private const TIMEOUT_SECONDS = 10;

    private const OUTSIDE_WINDOW_SUBCODE = 2018278;

    public function sendText(SocialChannel $channel, SocialConversation $conversation, string $text): SendResult
    {
        $provider = $channel->provider;
        $recipient = (string) ($conversation->contact_external_id ?? $conversation->external_conversation_id);

        // Atajo SOLO-LOCAL para verificación visual sin tokens reales (no toca red).
        $fake = config('social.fake_send');
        if (is_string($fake) && $fake !== '' && app()->environment('local')) {
            return match ($fake) {
                'window' => SendResult::window('Simulado (local): fuera de la ventana de 24h.'),
                'error' => SendResult::failed('Simulado (local): error de API.'),
                default => SendResult::sent('FAKE_'.Str::upper(Str::random(12))),
            };
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        if ($token === '' || $recipient === '') {
            return SendResult::failed('Canal sin token o conversación sin destinatario.');
        }

        [$url, $payload] = $this->buildRequest($provider, $recipient, $text);

        // TEMP DEBUG (diagnóstico envío IG, error 190) — QUITAR tras el diagnóstico. Confirma
        // si el token llega ÍNTEGRO al punto de envío. No expone el token completo.
        Log::info('social.send.debug.token', [
            'provider' => $provider,
            'token_len' => strlen($token),
            'token_trimmed_len' => strlen(trim($token)),
            'token_head' => substr($token, 0, 6),
            'token_tail' => substr($token, -4),
            'has_whitespace' => (bool) preg_match('/\s/', $token),
            'auth_via' => 'header Authorization: Bearer',
            'url' => $url,
        ]);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('social.send: error de red', ['provider' => $provider, 'error' => $e->getMessage()]);

            return SendResult::failed('No se pudo contactar con Meta (red).');
        }

        if ($response->successful()) {
            $id = $response->json('message_id');

            return SendResult::sent(is_string($id) ? $id : '');
        }

        $code = (int) ($response->json('error.code') ?? 0);
        $subcode = (int) ($response->json('error.error_subcode') ?? 0);
        $message = (string) ($response->json('error.message') ?? 'Error desconocido de Meta.');
        Log::warning('social.send: Meta rechazó el envío', compact('provider', 'code', 'subcode', 'message'));

        return $this->isOutsideWindow($code, $subcode, $message)
            ? SendResult::window($message)
            : SendResult::failed($message);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildRequest(string $provider, string $recipient, string $text): array
    {
        $version = (string) config('social.graph_version', 'v26.0');

        if ($provider === 'instagram') {
            // Page Access Token → nodo 'me': la Página la determina el token, no un {page_id}
            // ni el IG User ID. Payload básico oficial: recipient + message.
            return [
                "https://graph.facebook.com/{$version}/me/messages",
                ['recipient' => ['id' => $recipient], 'message' => ['text' => $text]],
            ];
        }

        // messenger
        return [
            "https://graph.facebook.com/{$version}/me/messages",
            ['recipient' => ['id' => $recipient], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text]],
        ];
    }

    private function isOutsideWindow(int $code, int $subcode, string $message): bool
    {
        if ($subcode === self::OUTSIDE_WINDOW_SUBCODE) {
            return true;
        }

        // Respaldo por si el subcódigo no viniera: code 10 (permiso/ventana) + pista textual.
        return $code === 10 && (stripos($message, '24') !== false || stripos($message, 'window') !== false);
    }
}
