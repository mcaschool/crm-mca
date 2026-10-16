<?php

declare(strict_types=1);

namespace Modules\TeamChat\Integration;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\EventPolicy;
use Modules\TeamChat\Services\ChatAccess;

final class ChatEventPolicy implements EventPolicy
{
    public function __construct(private ChatAccess $access) {}

    public function validate(EventEnvelope $event): void
    {
        $fields = match ($event->type) {
            'teamchat.conversation.created',
            'teamchat.group.created', 'teamchat.group.updated', 'teamchat.group.members.changed',
            'teamchat.team.created', 'teamchat.team.updated', 'teamchat.team.members.changed',
            'teamchat.channel.created', 'teamchat.channel.updated', 'teamchat.channel.members.changed' => ['conversation_id'],
            'teamchat.message.mentioned' => ['conversation_id', 'message_id', 'user_id'],
            'teamchat.message.edited','teamchat.message.deleted','teamchat.message.reaction.changed','teamchat.message.pin.changed','teamchat.message.created' => ['conversation_id', 'message_id', 'sequence'],
            'teamchat.receipt.delivered', 'teamchat.receipt.read' => ['conversation_id', 'message_ids', 'user_id'],
            'teamchat.membership.changed' => ['conversation_id', 'user_id', 'active'],
            default => throw new \InvalidArgumentException('Unknown TeamChat event'),
        };
        $keys = array_keys($event->data);
        sort($keys);
        sort($fields);
        if ($event->version !== 1 || $event->aggregateType !== 'teamchat_conversation' || $keys !== $fields || ($event->data['conversation_id'] ?? null) !== $event->aggregateId) {
            throw new \InvalidArgumentException('Invalid TeamChat payload');
        }
        if (array_key_exists('message_id', $event->data) && (! is_string($event->data['message_id']) || ! \Illuminate\Support\Str::isUlid($event->data['message_id']))) {
            throw new \InvalidArgumentException('Invalid message id');
        }
        if (array_key_exists('sequence', $event->data) && (! is_int($event->data['sequence']) || $event->data['sequence'] < 1)) {
            throw new \InvalidArgumentException('Invalid sequence');
        }
        if (array_key_exists('message_ids', $event->data)) {
            $ids = $event->data['message_ids'];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) < 1 || count($ids) > 50 || count(array_unique($ids)) !== count($ids)) {
                throw new \InvalidArgumentException('Invalid receipt ids');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! \Illuminate\Support\Str::isUlid($id)) {
                    throw new \InvalidArgumentException('Invalid receipt id');
                }
            }
        }
        if (array_key_exists('user_id', $event->data) && (! is_int($event->data['user_id']) || $event->data['user_id'] < 1)) {
            throw new \InvalidArgumentException('Invalid user id');
        }
        if (array_key_exists('active', $event->data) && ! is_bool($event->data['active'])) {
            throw new \InvalidArgumentException('Invalid active flag');
        }
    }

    public function allows(ActorContext $actor, EventEnvelope $event): bool
    {
        try {
            $row = $this->access->conversation($actor, $event->aggregateId);
            $from = $this->access->fromTick($actor, $row);
            $tick = (int) \Illuminate\Support\Facades\DB::table('collab_chat_event_access')->where('institution_id', $actor->institutionId)->where('event_id', $event->id)->value('access_tick');
            if ($tick < $from) {
                return false;
            }
            $ids = $event->data['message_ids'] ?? (isset($event->data['message_id']) ? [$event->data['message_id']] : []);
            if ($ids && \Illuminate\Support\Facades\DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $row->id)->whereIn('id', $ids)->where('access_tick', '>=', $from)->count() !== count($ids)) {
                return false;
            }

            if (in_array($event->type, ['teamchat.message.created', 'teamchat.message.mentioned'], true)) {
                $message = \Illuminate\Support\Facades\DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('id', $event->data['message_id'])->first();
                if (! $message || $message->deleted_at !== null) {
                    return false;
                }
            }
            if ($event->type === 'teamchat.message.mentioned') {
                return $event->data['user_id'] === $actor->userId && \Illuminate\Support\Facades\DB::table('collab_chat_mentions')->where('message_id', $event->data['message_id'])->where('user_id', $actor->userId)->where('active', true)->exists();
            }

            return true;
        } catch (ContractException) {
            return false;
        }
    }

    public function ephemeral(): bool
    {
        return false;
    }
}
