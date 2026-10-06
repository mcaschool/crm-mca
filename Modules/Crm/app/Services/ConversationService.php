<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Illuminate\Support\Str;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;

/**
 * Conversaciones del widget: crear (modo guided/celia), asociar contacto y
 * marcar actividad. El widget real llega en el Bloque 5; aqui esta la API.
 */
class ConversationService
{
    /**
     * @param  array<string,mixed>  $attrs  bot_id (requerido), session_id, mode,
     *                                      language, channel, contact_id, is_test, external_id
     */
    public function start(array $attrs): Conversation
    {
        $conversation = new Conversation;
        $conversation->bot_id = (int) $attrs['bot_id'];
        $conversation->contact_id = $attrs['contact_id'] ?? null;
        $conversation->session_id = (string) ($attrs['session_id'] ?? Str::uuid());
        $conversation->channel = (string) ($attrs['channel'] ?? 'web');
        $conversation->is_test = (bool) ($attrs['is_test'] ?? false);
        $conversation->external_id = isset($attrs['external_id']) ? (string) $attrs['external_id'] : null;
        $conversation->mode = (string) ($attrs['mode'] ?? 'guided');
        $conversation->language = (string) ($attrs['language'] ?? 'es');
        $conversation->status = 'open';
        $conversation->started_at = now();
        $conversation->last_activity_at = now();
        $conversation->save();

        return $conversation;
    }

    public function attachContact(Conversation $conversation, Contact $contact): Conversation
    {
        $conversation->contact_id = $contact->getKey();
        $conversation->last_activity_at = now();
        $conversation->save();

        return $conversation;
    }

    /** Cambia el modo (guided <-> celia) y marca actividad. */
    public function switchMode(Conversation $conversation, string $mode): Conversation
    {
        $conversation->mode = $mode;
        $conversation->last_activity_at = now();
        $conversation->save();

        return $conversation;
    }

    public function touch(Conversation $conversation): void
    {
        $conversation->last_activity_at = now();
        $conversation->save();
    }
}
