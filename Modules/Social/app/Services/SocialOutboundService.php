<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Social\Exceptions\UnsupportedSocialProviderException;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * Orquesta el envío saliente desde el panel: persiste el mensaje, llama a Meta y refleja el
 * resultado. Trabaja dentro del contexto de institución ya fijado por el panel (el trait
 * sella institution_id). Instagram y Messenger salen por MetaMessageSender (me/messages);
 * WhatsApp por WhatsAppMessageSender (canal propio, /{PHONE_NUMBER_ID}/messages), con
 * soporte de adjuntos vía la Media API (subida por media id, nunca por URL pública).
 */
final class SocialOutboundService
{
    /** Proveedores con salida habilitada. */
    public const SENDABLE = ['instagram', 'messenger', 'whatsapp'];

    public function __construct(
        private readonly MetaMessageSender $sender,
        private readonly WhatsAppMessageSender $whatsapp,
        private readonly WhatsAppMediaService $media,
    ) {}

    public function send(SocialConversation $conversation, string $text, User $user): SocialMessage
    {
        if (! in_array($conversation->provider, self::SENDABLE, true)) {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }

        $channel = $conversation->channel;

        // 1) Persistir el saliente en 'pending' (aparece de inmediato en el hilo).
        $message = $this->newOutbound($conversation, 'text', $text, null, $user);

        // 2) Enviar a Meta y 3) reflejar el resultado.
        $result = match (true) {
            $channel === null => SendResult::failed('La conversación no tiene canal asociado.'),
            $conversation->provider === 'whatsapp' => $this->whatsapp->sendText($channel, $conversation, $text),
            default => $this->sender->sendText($channel, $conversation, $text),
        };

        $this->applyResult($message, $result);
        $this->touchConversation($conversation, $text, $message);

        return $message;
    }

    /**
     * Envía un ADJUNTO por WhatsApp: clasifica por MIME real, guarda la copia privada para
     * la bandeja, sube el binario a la Media API y envía por media id ($caption opcional).
     * Lanza RuntimeException con mensaje apto para el usuario si el archivo no es enviable.
     */
    public function sendWhatsAppMedia(SocialConversation $conversation, UploadedFile $file, string $caption, User $user): SocialMessage
    {
        if ($conversation->provider !== 'whatsapp') {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }

        $channel = $conversation->channel;
        [$type, $mime] = $this->media->classifyUpload($file); // RuntimeException si no es enviable

        $stored = $this->media->storeUpload((int) $conversation->institution_id, $file, $mime);
        $caption = trim($caption);

        $attachment = [
            'type' => $type,
            'mime' => $mime,
            'storage_path' => $stored['storage_path'],
            'filename' => $stored['filename'],
            'size' => $stored['size'],
        ];
        $message = $this->newOutbound($conversation, $type, $caption !== '' ? $caption : null, [$attachment], $user);

        $result = SendResult::failed('La conversación no tiene canal asociado.');
        if ($channel !== null) {
            $mediaId = $this->media->upload(
                $channel,
                Storage::disk('local')->path($stored['storage_path']),
                $mime,
                $stored['filename'],
            );

            if ($mediaId === null) {
                $result = SendResult::failed('No se pudo subir el archivo a WhatsApp. Inténtalo de nuevo.');
            } else {
                $attachment['provider_media_id'] = $mediaId;
                $message->attachments = [$attachment];
                $result = $this->whatsapp->sendMedia(
                    $channel,
                    $conversation,
                    $type,
                    $mediaId,
                    $caption !== '' ? $caption : null,
                    $type === 'document' ? $stored['filename'] : null,
                );
            }
        }

        $this->applyResult($message, $result);
        $this->touchConversation($conversation, $caption !== '' ? $caption : '['.$type.']', $message);

        return $message;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    private function newOutbound(SocialConversation $conversation, string $type, ?string $body, ?array $attachments, User $user): SocialMessage
    {
        $message = new SocialMessage;
        $message->social_conversation_id = $conversation->id;
        $message->external_message_id = null;
        $message->direction = 'outbound';
        $message->type = $type;
        $message->body = $body;
        $message->attachments = $attachments;
        $message->status = 'pending';
        $message->sender_type = 'agent';
        $message->sent_by = $user->id;
        $message->provider_timestamp = now();
        $message->save();

        return $message;
    }

    private function applyResult(SocialMessage $message, SendResult $result): void
    {
        $message->status = $result->status;                 // sent | failed | failed_window
        if ($result->externalId !== null && $result->externalId !== '') {
            // El id de Meta (wamid en WhatsApp) permite deduplicar echoes y, en WhatsApp,
            // reconciliar los estados sent/delivered/read/failed del webhook.
            $message->external_message_id = $result->externalId;
        }
        $message->save();
    }

    private function touchConversation(SocialConversation $conversation, string $preview, SocialMessage $message): void
    {
        // Conversación: preview + last_message_at; unread_count NO cambia (es saliente).
        $conversation->last_message_preview = Str::limit($preview, 140);
        $conversation->last_message_at = $message->provider_timestamp;
        $conversation->save();
    }
}
