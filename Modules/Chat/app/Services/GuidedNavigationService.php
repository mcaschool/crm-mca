<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Services\EventService;

/**
 * Motor de navegacion guiada (SIN IA): renderiza los nodos del arbol administrable
 * y avanza segun los botones. Cada clic con event_type (de la opcion o del nodo
 * destino) registra su evento en el CRM.
 */
class GuidedNavigationService
{
    public function __construct(private readonly EventService $events) {}

    public function findNode(int $botId, string $key): ?ConversationNode
    {
        return ConversationNode::query()
            ->where('bot_id', $botId)
            ->where('key', $key)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Serializa un nodo (localizado) para el widget: contenido + opciones. Sustituye
     * el marcador [Nombre]/[Name] por el nombre del contacto si se proporciona.
     *
     * @return array<string,mixed>
     */
    public function renderNode(ConversationNode $node, string $locale, ?string $name = null): array
    {
        $options = $node->options()
            ->orderBy('display_order')
            ->get()
            ->map(function (ConversationOption $option) use ($locale): array {
                $targetKey = $option->target_node_id !== null
                    ? optional(ConversationNode::query()->find($option->target_node_id))->key
                    : null;

                return [
                    'id' => $option->getKey(),
                    'label' => $option->translate('label', $locale),
                    'target_key' => $targetKey,
                    'action' => $option->action,
                    'url' => $option->url,
                ];
            })->all();

        return [
            'key' => $node->key,
            'type' => $node->type,
            'content' => $this->personalize($node->translate('content', $locale), $name),
            'options' => $options,
        ];
    }

    /**
     * Procesa el clic de una opcion: registra su evento (y el del nodo destino),
     * avanza el nodo actual y devuelve el nodo destino, la accion y la url.
     *
     * @return array<string,mixed>
     */
    public function choose(Conversation $conversation, ConversationOption $option, string $locale, ?string $name = null): array
    {
        // Evento de la opcion clicada.
        $this->recordEvent($conversation, $option->event_type, ['option_id' => $option->getKey()]);

        $target = $option->target_node_id !== null
            ? ConversationNode::query()->find($option->target_node_id)
            : null;

        if ($target !== null) {
            $conversation->current_node_id = $target->getKey();
            $conversation->last_activity_at = now();
            $conversation->save();

            // Evento de nodo destino (se registra al verlo), guardado en config.
            $nodeEvent = is_array($target->config) ? ($target->config['event_type'] ?? null) : null;
            $this->recordEvent($conversation, is_string($nodeEvent) ? $nodeEvent : null, ['node_key' => $target->key]);

            return ['node' => $this->renderNode($target, $locale, $name), 'action' => $option->action, 'url' => $option->url];
        }

        // Opcion de pura accion (start_matcher / start_celia / external_link).
        $conversation->last_activity_at = now();
        $conversation->save();

        return ['node' => null, 'action' => $option->action, 'url' => $option->url];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function recordEvent(Conversation $conversation, ?string $eventType, array $data): void
    {
        if ($eventType === null || $eventType === '') {
            return;
        }

        $this->events->record($eventType, [
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->getKey(),
            'bot_id' => $conversation->bot_id,
            'data' => $data,
        ]);
    }

    private function personalize(?string $content, ?string $name): ?string
    {
        if ($content === null) {
            return null;
        }

        return str_replace(['[Nombre]', '[Name]'], (string) ($name ?? ''), $content);
    }
}
