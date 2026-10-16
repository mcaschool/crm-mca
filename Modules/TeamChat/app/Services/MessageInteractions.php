<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Support\Guard;

final class MessageInteractions
{
    public function __construct(private ChatAccess $access, private ChatEvents $events) {}

    public function message(ActorContext $actor, string $conversation, string $id, bool $writing = false): object
    {
        $space = $this->access->conversation($actor, $conversation, $writing);
        $row = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $conversation)->where('id', $id)->where('access_tick', '>=', $this->access->fromTick($actor, $space))->first();
        if (! $row) {
            throw new ContractException('not_found', 404);
        }

        return $row;
    }

    /** Tokens contain server-verified IDs, never display-name matches.
     * @return list<int>
     */
    public function mentionIds(ActorContext $actor, object $conversation, string $body, ?object $message = null): array
    {
        preg_match_all('/@\[(\d+)\]/u', $body, $matches);
        $ids = array_values(array_unique(array_map('intval', $matches[1])));
        sort($ids);
        if (count($ids) > 10) {
            throw new ContractException('invalid', 422);
        }
        foreach ($ids as $id) {
            $member = $this->access->membershipRow($conversation, $id);
            if (! $member || ! $this->access->memberActive($actor->institutionId, $conversation->id, $id) || ($message && (int) $message->access_tick < (int) $member->from_tick)) {
                throw new ContractException('not_found', 404);
            }
        }

        return $ids;
    }

    /** Called within the existing message transaction. Once per message/user; removing and readding is not a new alert.
     * @param  list<int>  $ids
     */
    public function mentions(ActorContext $actor, object $conversation, string $message, array $ids): void
    {
        DB::table('collab_chat_mentions')->where('message_id', $message)->whereNotIn('user_id', $ids)->update(['active' => false, 'updated_at' => now()]);
        foreach ($ids as $id) {
            $existing = DB::table('collab_chat_mentions')->where('message_id', $message)->where('user_id', $id)->first();
            if ($existing) {
                DB::table('collab_chat_mentions')->where('id', $existing->id)->update(['active' => true, 'updated_at' => now()]);

                continue;
            }
            DB::table('collab_chat_mentions')->insert(['institution_id' => $actor->institutionId, 'message_id' => $message, 'user_id' => $id, 'active' => true, 'access_tick' => app(ChatClock::class)->next($actor->institutionId), 'created_at' => now(), 'updated_at' => now()]);
            if ($id === $actor->userId) {
                continue;
            }
            $event = $this->events->record($actor, $conversation, 'teamchat.message.mentioned', ['message_id' => $message, 'user_id' => $id]);
            app(NotificationDispatcher::class)->enqueue(new InstitutionContext($actor->institutionId),
                new NotificationIntent((string) Str::ulid(), 'teamchat.message.mentioned', $conversation->id, $event, 'mention.'.$message.'.'.$id, now()->addDay()->toDateTimeImmutable()), new NotificationAudience($actor->institutionId, [$id]));
        }
    }

    public function canPin(ActorContext $actor, object $space): bool
    {
        return $space->type === 'direct' || in_array($this->access->membershipRow($space, $actor->userId)?->role, ['owner', 'admin'], true);
    }

    /** @param array<string,mixed> $data */
    public function change(ActorContext $actor, string $conversation, string $message, string $command, string $operation, array $data = []): void
    {
        Guard::token($command);
        if (! in_array($operation, ['edit', 'delete', 'react', 'unreact', 'pin', 'unpin'], true)) {
            throw new ContractException('invalid', 422);
        }
        $hash = hash('sha256', json_encode([$conversation, $message, $operation, $data], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($actor, $conversation, $message, $command, $operation, $data, $hash): void {
            DB::table('institutions')->where('id', $actor->institutionId)->lockForUpdate()->firstOrFail();
            $space = $this->access->conversation($actor, $conversation, true);
            $row = $this->message($actor, $conversation, $message, true);
            $old = DB::table('collab_chat_message_commands')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->where('command_id', $command)->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new ContractException('idempotency_conflict', 409);
                }

                return;
            }
            if ($row->deleted_at !== null && $operation !== 'delete') {
                throw new ContractException('not_found', 404);
            }
            $changed = false;
            $event = '';
            if (in_array($operation, ['edit', 'delete'], true)) {
                $minutes = $operation === 'edit' ? (int) config('teamchat.interactions.edit_minutes') : (int) config('teamchat.interactions.delete_hours') * 60;
                if ((int) $row->sender_id !== $actor->userId || now()->greaterThan(\Illuminate\Support\Carbon::parse($row->created_at)->addMinutes($minutes))) {
                    throw new ContractException('forbidden', 403);
                }
                if ($row->deleted_at === null) {
                    if ((int) ($data['version'] ?? 0) !== (int) $row->version) {
                        throw new ContractException('idempotency_conflict', 409);
                    }
                    $body = $operation === 'edit' ? trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['body'] ?? ''))) : $row->body;
                    if ($operation === 'edit' && ($row->body === '' || $body === '' || mb_strlen($body) > 5000 || ! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0"))) {
                        throw new ContractException('invalid', 422);
                    }
                    $mentions = $operation === 'edit' ? $this->mentionIds($actor, $space, $body, $row) : [];
                    $updates = ['version' => (int) $row->version + 1, $operation === 'edit' ? 'edited_at' : 'deleted_at' => now()];
                    if ($operation === 'edit') {
                        $updates['body'] = $body;
                    }
                    DB::table('collab_chat_messages')->where('id', $message)->update($updates);
                    if ($operation === 'delete') {
                        DB::table('collab_chat_reactions')->where('message_id', $message)->delete();
                        DB::table('collab_chat_pins')->where('message_id', $message)->delete();
                    }
                    $this->mentions($actor, $space, $message, $mentions);
                    DB::table('collab_chat_message_audit')->insert(['institution_id' => $actor->institutionId, 'message_id' => $message, 'actor_id' => $actor->userId, 'operation' => $operation, 'version' => $updates['version'], 'before_hash' => hash('sha256', $row->body), 'after_hash' => hash('sha256', $operation === 'delete' ? '' : $body), 'created_at' => now()]);
                    $changed = true;
                    $event = $operation === 'edit' ? 'edited' : 'deleted';
                }
            } elseif (in_array($operation, ['react', 'unreact'], true)) {
                $emoji = (string) ($data['emoji'] ?? '');
                if (! in_array($emoji, config('teamchat.interactions.emojis'), true)) {
                    throw new ContractException('invalid', 422);
                }
                $q = DB::table('collab_chat_reactions')->where('message_id', $message)->where('user_id', $actor->userId)->where('emoji', $emoji);
                if ($operation === 'react' && ! $q->exists()) {
                    DB::table('collab_chat_reactions')->insert(['institution_id' => $actor->institutionId, 'message_id' => $message, 'user_id' => $actor->userId, 'emoji' => $emoji, 'access_tick' => app(ChatClock::class)->next($actor->institutionId), 'created_at' => now(), 'updated_at' => now()]);
                    $changed = true;
                } elseif ($operation === 'unreact') {
                    $changed = $q->delete() > 0;
                }
                $event = 'reaction.changed';
            } else {
                if (! $this->canPin($actor, $space)) {
                    throw new ContractException('forbidden', 403);
                }
                $q = DB::table('collab_chat_pins')->where('message_id', $message);
                if ($operation === 'pin' && ! $q->exists()) {
                    $count = DB::table('collab_chat_pins as p')->join('collab_chat_messages as m', 'm.id', '=', 'p.message_id')->where('m.conversation_id', $conversation)->whereNull('m.deleted_at')->count();
                    if ($count >= (int) config('teamchat.interactions.pin_limit')) {
                        throw new ContractException('invalid', 422);
                    }
                    DB::table('collab_chat_pins')->insert(['institution_id' => $actor->institutionId, 'message_id' => $message, 'user_id' => $actor->userId, 'access_tick' => app(ChatClock::class)->next($actor->institutionId), 'created_at' => now(), 'updated_at' => now()]);
                    $changed = true;
                } elseif ($operation === 'unpin') {
                    $changed = $q->delete() > 0;
                }
                $event = 'pin.changed';
            }
            if ($changed) {
                $this->events->record($actor, $space, 'teamchat.message.'.$event, ['message_id' => $message, 'sequence' => (int) $row->sequence]);
            }
            DB::table('collab_chat_message_commands')->insert(['institution_id' => $actor->institutionId, 'user_id' => $actor->userId, 'command_id' => $command, 'request_hash' => $hash, 'message_id' => $message, 'created_at' => now()]);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function metadata(ActorContext $actor, object $row): array
    {
        $space = $this->access->conversation($actor, $row->conversation_id);
        $from = $this->access->fromTick($actor, $space);
        if ((int) $row->institution_id !== $actor->institutionId || (int) $row->access_tick < $from) {
            throw new ContractException('not_found', 404);
        }
        $deleted = $row->deleted_at !== null;
        $reply = null;
        if ($row->reply_to_id && ! $deleted) {
            $original = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $row->conversation_id)->where('id', $row->reply_to_id)->where('access_tick', '>=', $from)->first();
            $reply = ['available' => false];
            if ($original) {
                $reply = ['available' => true, 'id' => $original->id, 'deleted' => $original->deleted_at !== null];
                if ($original->deleted_at === null) {
                    $reply += ['author' => User::query()->where('institution_id', $actor->institutionId)->whereKey($original->sender_id)->value('name'), 'body' => mb_substr(preg_replace('/@\[\d+\]/', '@…', $original->body), 0, 160), 'attachment' => DB::table('collab_chat_uploads')->where('message_id', $original->id)->value('kind')];
                }
            }
        }
        $reactions = [];
        $mentions = [];
        if (! $deleted) {
            foreach (DB::table('collab_chat_reactions')->where('message_id', $row->id)->where('access_tick', '>=', $from)->orderBy('id')->get() as $reaction) {
                $member = $this->access->membershipRow($space, (int) $reaction->user_id);
                if (! $member || (int) $reaction->access_tick < (int) $member->from_tick || ! $this->access->userActive($actor->institutionId, (int) $reaction->user_id)) {
                    continue;
                }
                $emoji = $reaction->emoji;
                if (! isset($reactions[$emoji])) {
                    $reactions[$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false, 'users' => []];
                }
                $reactions[$emoji]['count']++;
                $reactions[$emoji]['mine'] = $reactions[$emoji]['mine'] || (int) $reaction->user_id === $actor->userId;
                $reactions[$emoji]['users'][] = ['id' => (int) $reaction->user_id, 'name' => User::query()->where('institution_id', $actor->institutionId)->whereKey($reaction->user_id)->value('name')];
            }
            foreach (DB::table('collab_chat_mentions')->where('message_id', $row->id)->where('active', true)->get() as $mention) {
                if ($this->access->memberActive($actor->institutionId, $space->id, (int) $mention->user_id)) {
                    $mentions[] = ['id' => (int) $mention->user_id, 'name' => User::query()->where('institution_id', $actor->institutionId)->whereKey($mention->user_id)->value('name')];
                }
            }
        }
        $own = (int) $row->sender_id === $actor->userId;
        $canSend = $this->access->canSend($space, $actor->userId);

        return ['version' => (int) $row->version, 'deleted' => $deleted, 'edited_at' => $row->edited_at, 'reply' => $reply, 'mentions' => $mentions, 'reactions' => array_values($reactions),
            'pinned' => ! $deleted && DB::table('collab_chat_pins')->where('message_id', $row->id)->exists(),
            'can_edit' => ! $deleted && $own && $canSend && $row->body !== '' && now()->lessThanOrEqualTo(\Illuminate\Support\Carbon::parse($row->created_at)->addMinutes((int) config('teamchat.interactions.edit_minutes'))),
            'can_delete' => ! $deleted && $own && $canSend && now()->lessThanOrEqualTo(\Illuminate\Support\Carbon::parse($row->created_at)->addHours((int) config('teamchat.interactions.delete_hours'))),
            'can_pin' => ! $deleted && $canSend && $this->canPin($actor, $space)];
    }
}
