<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Services\EventService;

/**
 * Motor de navegacion guiada (SIN IA): renderiza los nodos del arbol administrable
 * y avanza segun los botones. Cada clic con event_type registra su evento en el CRM.
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
     * Serializa un nodo (localizado) para el widget: contenido + opciones.
     *
     * @return array<string,mixed>
     */
    public function renderNode(ConversationNode $node, string $locale): array
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
                ];
            })->all();

        return [
            'key' => $node->key,
            'type' => $node->type,
            'content' => $node->translate('content', $locale),
            'config' => $node->config,
            'options' => $options,
        ];
    }

    /**
     * Procesa el clic de una opcion: registra su evento, avanza el nodo actual y
     * devuelve el nodo destino (o el marcador de accion si no hay destino).
     *
     * @return array<string,mixed>
     */
    public function choose(Conversation $conversation, ConversationOption $option, string $locale): array
    {
        if ($option->event_type !== null && $option->event_type !== '') {
            $this->events->record($option->event_type, [
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->getKey(),
                'bot_id' => $conversation->bot_id,
                'data' => ['option_id' => $option->getKey()],
            ]);
        }

        $target = $option->target_node_id !== null
            ? ConversationNode::query()->find($option->target_node_id)
            : null;

        if ($target !== null) {
            $conversation->current_node_id = $target->getKey();
            $conversation->last_activity_at = now();
            $conversation->save();

            return ['node' => $this->renderNode($target, $locale), 'action' => $option->action];
        }

        // Opcion de pura accion (start_matcher / start_celia / external link).
        $conversation->last_activity_at = now();
        $conversation->save();

        return ['node' => null, 'action' => $option->action];
    }
}
