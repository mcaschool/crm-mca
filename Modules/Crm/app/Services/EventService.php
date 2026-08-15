<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Event;

/**
 * Registro de eventos de comportamiento. "Todo comportamiento deja rastro",
 * aunque la respuesta sea un enlace.
 */
class EventService
{
    /**
     * @param  array<string,mixed>  $context  contact_id, conversation_id, bot_id, data
     */
    public function record(EventType|string $type, array $context = []): Event
    {
        $event = new Event;
        $event->event_type = $type instanceof EventType ? $type->value : $type;
        $event->contact_id = $context['contact_id'] ?? null;
        $event->conversation_id = $context['conversation_id'] ?? null;
        $event->bot_id = $context['bot_id'] ?? null;
        $event->event_data = isset($context['data']) && $context['data'] !== [] ? $context['data'] : null;
        $event->save();

        return $event;
    }
}
