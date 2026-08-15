<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;

/**
 * Registro de mensajes de una conversacion. `meta` se reserva para el AI
 * Deflection Rate (provider, model, tokens, latency, cost); en este bloque puede
 * ir vacio porque aun no hay IA. Registrar un mensaje marca actividad en la
 * conversacion (importante para la granularidad D4 del lead).
 */
class MessageService
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function record(
        Conversation $conversation,
        string $senderType,
        string $content,
        string $messageType = 'text',
        array $meta = [],
    ): Message {
        $message = new Message;
        $message->conversation_id = $conversation->getKey();
        $message->sender_type = $senderType;
        $message->content = $content;
        $message->message_type = $messageType;
        $message->meta = $meta === [] ? null : $meta;
        $message->save();

        // Marca actividad (append-only: la conversacion sirve de "reloj").
        $conversation->last_activity_at = now();
        $conversation->save();

        return $message;
    }
}
