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
 * Envía mensajes salientes por WhatsApp Cloud API (canal propio del CRM). Endpoint oficial:
 *   POST https://graph.facebook.com/{version}/{PHONE_NUMBER_ID}/messages
 *   Authorization: Bearer <token del canal>   (el token NUNCA viaja en la URL ni se loguea)
 *   body base: {messaging_product:'whatsapp', recipient_type:'individual', to:<wa_id>, ...}
 *
 * El Phone Number ID sale de SocialChannel.external_id y el token de
 * SocialChannel.credentials['token'] (cifrado en BD) — nada hardcodeado.
 *
 * PUNTO DE EXTENSIÓN — plantillas (templates): todos los envíos pasan por dispatch(), que
 * recibe el fragmento específico del tipo ({type:'text',text:{...}} | {type:'image',image:{...}}).
 * Cuando se implemente el envío de plantillas bastará añadir un sendTemplate() que arme
 * {type:'template', template:{name, language, components}} y delegue en dispatch(), sin
 * tocar el armado del request, los errores ni la detección de ventana.
 *
 * Ventana de servicio de 24h: fuera de ella Meta responde 400 con error.code 131047
 * ("Re-engagement message"; 470 en versiones antiguas) → se traduce a 'failed_window'
 * (la UI informa que se requiere una plantilla aprobada para reanudar la conversación).
 */
final class WhatsAppMessageSender
{
    private const TIMEOUT_SECONDS = 15;

    /** Códigos de Meta que significan "fuera de la ventana de servicio / requiere plantilla". */
    private const OUTSIDE_WINDOW_CODES = [131047, 470];

    public function sendText(SocialChannel $channel, SocialConversation $conversation, string $text): SendResult
    {
        return $this->dispatch($channel, $conversation, ['type' => 'text', 'text' => ['body' => $text]]);
    }

    /**
     * Envía una PLANTILLA aprobada (type=template). $components es la lista oficial de
     * componentes con parámetros (p. ej. body con parameters posicionales), o [] si la
     * plantilla no tiene variables.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public function sendTemplate(SocialChannel $channel, SocialConversation $conversation, string $name, string $languageCode, array $components = []): SendResult
    {
        $template = ['name' => $name, 'language' => ['code' => $languageCode]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->dispatch($channel, $conversation, ['type' => 'template', 'template' => $template]);
    }

    /**
     * Envía un adjunto ya subido a la Media API de WhatsApp (referenciado por media id).
     * caption solo aplica a image/video/document; filename solo a document.
     *
     * @param  'image'|'video'|'audio'|'document'  $type
     */
    public function sendMedia(SocialChannel $channel, SocialConversation $conversation, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): SendResult
    {
        $media = ['id' => $mediaId];
        if ($caption !== null && $caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($filename !== null && $filename !== '' && $type === 'document') {
            $media['filename'] = $filename;
        }

        return $this->dispatch($channel, $conversation, ['type' => $type, $type => $media]);
    }

    /**
     * Núcleo común: arma el request oficial y traduce la respuesta a SendResult.
     * $messagePayload es el fragmento específico del tipo (text/image/…/template en el futuro).
     *
     * @param  array<string, mixed>  $messagePayload
     */
    private function dispatch(SocialChannel $channel, SocialConversation $conversation, array $messagePayload): SendResult
    {
        // Atajo SOLO-LOCAL para verificación visual sin tokens reales (no toca red).
        $fake = config('social.fake_send');
        if (is_string($fake) && $fake !== '' && app()->environment('local')) {
            return match ($fake) {
                'window' => SendResult::window('Simulado (local): fuera de la ventana de 24h.'),
                'error' => SendResult::failed('Simulado (local): error de API.'),
                default => SendResult::sent('wamid.FAKE_'.Str::upper(Str::random(12))),
            };
        }

        // Canal offboarded (Coexistence): la API no acepta envíos hasta reconectar.
        if (! $channel->canSendViaApi()) {
            return SendResult::failed('El número está desconectado de la API (offboarded). Reconecta el canal para volver a enviar.');
        }

        $token = (string) ($channel->credentials['token'] ?? '');
        $phoneNumberId = (string) ($channel->external_id ?? '');
        $to = (string) ($conversation->contact_external_id ?? $conversation->external_conversation_id);

        if ($token === '' || $to === '') {
            return SendResult::failed('Canal sin token o conversación sin destinatario.');
        }
        if ($phoneNumberId === '') {
            return SendResult::failed('El canal de WhatsApp no tiene configurado el Phone Number ID.');
        }

        $version = (string) config('social.graph_version', 'v26.0');
        $payload = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ], $messagePayload);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);
        } catch (Throwable $e) {
            Log::warning('social.send.wa: error de red', ['error' => $e->getMessage()]);

            return SendResult::failed('No se pudo contactar con Meta (red).');
        }

        if ($response->successful()) {
            // El wamid devuelto se persiste como external_message_id: es la clave con la
            // que luego se reconcilian los estados sent/delivered/read/failed del webhook.
            $wamid = $response->json('messages.0.id');

            return SendResult::sent(is_string($wamid) ? $wamid : '');
        }

        $code = (int) ($response->json('error.code') ?? 0);
        $subcode = (int) ($response->json('error.error_subcode') ?? 0);
        $message = (string) ($response->json('error.message') ?? 'Error desconocido de Meta.');
        // Diagnóstico seguro: jamás token, Authorization ni payload completo.
        Log::warning('social.send.wa: Meta rechazó el envío', compact('code', 'subcode', 'message'));

        return in_array($code, self::OUTSIDE_WINDOW_CODES, true)
            ? SendResult::window($message)
            : SendResult::failed($message);
    }
}
