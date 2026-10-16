<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Support\Guard;

final class ChatCommands
{
    public function __construct(private ChatAccess $access, private CollaborationAccess $authorization, private UserDirectory $users,
        private ChatEvents $events, private NotificationDispatcher $notifications) {}

    public function direct(ActorContext $actor, int $otherUserId): string
    {
        if ($otherUserId === $actor->userId || $otherUserId < 1) {
            throw new ContractException('invalid', 422);
        }

        return DB::transaction(function () use ($actor, $otherUserId): string {
            $this->lockInstitution($actor);
            $ids = [$actor->userId, $otherUserId];
            sort($ids);
            User::query()->where('institution_id', $actor->institutionId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->authorization->authorize($actor, 'collaboration.use');
            $this->users->resolve($actor, $otherUserId);
            $existing = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)
                ->where('first_user_id', $ids[0])->where('second_user_id', $ids[1])->lockForUpdate()->first();
            if ($existing) {
                $this->access->conversation($actor, $existing->id);

                return $existing->id;
            }
            $id = (string) Str::ulid();
            DB::table('collab_chat_conversations')->insert(['id' => $id, 'institution_id' => $actor->institutionId,
                'first_user_id' => $ids[0], 'second_user_id' => $ids[1], 'message_sequence' => 0, 'event_version' => 0, 'access_version' => 1,
                'activity_at' => now()->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($ids as $userId) {
                DB::table('collab_chat_participants')->insert(['institution_id' => $actor->institutionId, 'conversation_id' => $id,
                    'user_id' => $userId, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $row = DB::table('collab_chat_conversations')->where('id', $id)->firstOrFail();
            $this->events->record($actor, $row, 'teamchat.conversation.created', []);

            return $id;
        }, 3);
    }

    /** @param list<string> $attachments */
    public function send(ActorContext $actor, string $conversationId, string $commandId, string $body, array $attachments = [], ?string $replyTo = null): string
    {
        Guard::token($commandId);
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if (($body === '' && $attachments === []) || ! mb_check_encoding($body, 'UTF-8') || mb_strlen($body) > 5000 || str_contains($body, "\0")) {
            throw new ContractException('invalid', 422);
        }
        sort($attachments);
        $payload = $attachments === [] ? [$conversationId, $body] : [$conversationId, $body, $attachments];
        if ($replyTo !== null) {
            $payload[] = ['reply_to' => $replyTo];
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $conversationId, $commandId, $body, $hash, $attachments, $replyTo): string {
            $this->lockInstitution($actor);
            $conversation = $this->lockConversation($actor, $conversationId);
            $this->access->conversation($actor, $conversationId, true);
            $existing = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('sender_id', $actor->userId)->where('command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ContractException('idempotency_conflict', 409);
                }

                if ((int) $existing->access_tick < $this->access->fromTick($actor, $conversation)) {
                    throw new ContractException('not_found', 404);
                }

                return $existing->id;
            }
            if ($replyTo !== null) {
                app(MessageInteractions::class)->message($actor, $conversationId, $replyTo, true);
            }
            $mentions = app(MessageInteractions::class)->mentionIds($actor, $conversation, $body);
            $id = (string) Str::ulid();
            $sequence = (int) $conversation->message_sequence + 1;
            DB::table('collab_chat_messages')->insert(['id' => $id, 'institution_id' => $actor->institutionId,
                'conversation_id' => $conversationId, 'sender_id' => $actor->userId, 'sequence' => $sequence,
                'command_id' => $commandId, 'request_hash' => $hash, 'body' => $body, 'reply_to_id' => $replyTo, 'access_tick' => app(ChatClock::class)->next($actor->institutionId), 'created_at' => now()]);
            DB::table('collab_chat_conversations')->where('id', $conversationId)->where('institution_id', $actor->institutionId)
                ->update(['message_sequence' => $sequence, 'activity_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);
            app(ChatAttachments::class)->attach($actor, $conversationId, $id, $attachments);
            $eventId = $this->events->record($actor, $conversation, 'teamchat.message.created', ['message_id' => $id, 'sequence' => $sequence]);
            $this->notifications->enqueue(new InstitutionContext($actor->institutionId),
                new NotificationIntent((string) Str::ulid(), 'teamchat.message.created', $conversationId, $eventId, 'message.'.$id, now()->addDay()->toDateTimeImmutable()),
                new NotificationAudience($actor->institutionId, array_values(array_diff($this->access->members($conversation), [$actor->userId], $mentions))));
            app(MessageInteractions::class)->mentions($actor, $conversation, $id, $mentions);

            return $id;
        }, 3);
    }

    /** Acknowledges only explicitly received/visible incoming messages, never an implied range.
     * @param  list<string>  $messageIds
     */
    public function acknowledge(ActorContext $actor, string $conversationId, array $messageIds, bool $read): void
    {
        foreach ($messageIds as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new ContractException('invalid', 422);
            }
        }
        if (! array_is_list($messageIds) || count($messageIds) < 1 || count($messageIds) > 50 || count(array_unique($messageIds)) !== count($messageIds)) {
            throw new ContractException('invalid', 422);
        }
        DB::transaction(function () use ($actor, $conversationId, $messageIds, $read): void {
            $this->lockInstitution($actor);
            $conversation = $this->lockConversation($actor, $conversationId);
            $this->access->conversation($actor, $conversationId);
            $messages = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $conversationId)->whereIn('id', $messageIds)->where('access_tick', '>=', $this->access->fromTick($actor, $conversation))->where('sender_id', '!=', $actor->userId)->get();
            if ($messages->count() !== count($messageIds)) {
                throw new ContractException('not_found', 404);
            }
            $changed = [];
            foreach ($messages as $message) {
                $receipt = DB::table('collab_chat_receipts')->where('message_id', $message->id)->where('user_id', $actor->userId)->lockForUpdate()->first();
                if ($receipt && (! $read || $receipt->read_at !== null)) {
                    continue;
                }
                if ($receipt) {
                    DB::table('collab_chat_receipts')->where('id', $receipt->id)->update(['read_at' => now()]);
                } else {
                    DB::table('collab_chat_receipts')->insert(['institution_id' => $actor->institutionId, 'conversation_id' => $conversationId,
                        'message_id' => $message->id, 'user_id' => $actor->userId, 'delivered_at' => now(), 'read_at' => $read ? now() : null]);
                }
                $changed[] = $message->id;
            }
            if ($changed) {
                $this->events->record($actor, $conversation, $read ? 'teamchat.receipt.read' : 'teamchat.receipt.delivered',
                    ['message_ids' => $changed, 'user_id' => $actor->userId]);
            }
        }, 3);
    }

    /** Own membership only. No participant substitution or group management. */
    public function membership(ActorContext $actor, string $conversationId, bool $active): void
    {
        if ($active) {
            throw new ContractException('forbidden', 403);
        } // Self-service can leave, never undo a revocation.
        $current = $this->access->conversation($actor, $conversationId);
        if ($current->type !== 'direct') {
            app(SpaceCommands::class)->member($actor, 'conversation', $conversationId, 'leave.'.Str::ulid(), $actor->userId, 'remove');

            return;
        }
        DB::transaction(function () use ($actor, $conversationId, $active): void {
            $this->lockInstitution($actor);
            $this->authorization->authorize($actor, 'collaboration.use');
            $conversation = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $conversationId)->lockForUpdate()->first();
            $member = DB::table('collab_chat_participants')->where('institution_id', $actor->institutionId)->where('conversation_id', $conversationId)->where('user_id', $actor->userId)->lockForUpdate()->first();
            if (! $conversation || ! $member) {
                throw new ContractException('not_found', 404);
            }
            if ((bool) $member->active === $active) {
                return;
            }
            DB::table('collab_chat_participants')->where('id', $member->id)->update(['active' => $active, 'updated_at' => now()]);
            app(\Modules\CollaborationCore\Contracts\RevocationRecorder::class)->record(new \Modules\CollaborationCore\Events\ConversationMembershipRevoked($actor->institutionId, $actor->userId, 'direct', $conversationId, 'left'));
            DB::table('collab_chat_conversations')->where('id', $conversationId)->increment('access_version');
            $this->events->record($actor, $conversation, 'teamchat.membership.changed', ['user_id' => $actor->userId, 'active' => $active]);
        }, 3);
    }

    private function lockInstitution(ActorContext $actor): void
    {
        DB::table('institutions')->where('id', $actor->institutionId)->lockForUpdate()->firstOrFail();
        $this->authorization->authorize($actor, 'collaboration.use');
    }

    private function lockConversation(ActorContext $actor, string $id): object
    {
        $row = $this->access->conversation($actor, $id);
        User::query()->where('institution_id', $actor->institutionId)->whereIn('id', [$row->first_user_id, $row->second_user_id])->orderBy('id')->lockForUpdate()->get();

        return DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
