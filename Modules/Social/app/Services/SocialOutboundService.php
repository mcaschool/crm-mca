<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Social\Exceptions\UnsupportedSocialProviderException;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * Orquesta el envío saliente desde el panel: persiste el mensaje, llama a Meta y refleja el
 * resultado. Trabaja dentro del contexto de institución ya fijado por el panel (el trait
 * sella institution_id); solo maneja Instagram + Messenger (WhatsApp queda fuera del bloque).
 */
final class SocialOutboundService
{
    /** Proveedores con salida habilitada en este bloque. */
    public const SENDABLE = ['instagram', 'messenger'];

    public function __construct(private readonly MetaMessageSender $sender) {}

    public function send(SocialConversation $conversation, string $text, User $user): SocialMessage
    {
        if (! in_array($conversation->provider, self::SENDABLE, true)) {
            throw UnsupportedSocialProviderException::for($conversation->provider);
        }

        $channel = $conversation->channel;

        // 1) Persistir el saliente en 'pending' (aparece de inmediato en el hilo).
        $message = new SocialMessage;
        $message->social_conversation_id = $conversation->id;
        $message->external_message_id = null;
        $message->direction = 'outbound';
        $message->type = 'text';
        $message->body = $text;
        $message->status = 'pending';
        $message->sender_type = 'agent';
        $message->sent_by = $user->id;
        $message->provider_timestamp = now();
        $message->save();

        // 2) Enviar a Meta y 3) reflejar el resultado.
        $result = $channel !== null
            ? $this->sender->sendText($channel, $conversation, $text)
            : SendResult::failed('La conversación no tiene canal asociado.');

        $message->status = $result->status;                 // sent | failed | failed_window
        if ($result->externalId !== null && $result->externalId !== '') {
            // Guardar el id de Meta permite deduplicar un eventual echo del mismo mensaje.
            $message->external_message_id = $result->externalId;
        }
        $message->save();

        // 4) Conversación: preview + last_message_at; unread_count NO cambia (es saliente).
        $conversation->last_message_preview = Str::limit($text, 140);
        $conversation->last_message_at = $message->provider_timestamp;
        $conversation->save();

        return $message;
    }
}
