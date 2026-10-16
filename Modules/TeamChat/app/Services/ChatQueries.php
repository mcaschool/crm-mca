<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;

final class ChatQueries
{
    public function __construct(private ChatAccess $access, private CollaborationAccess $authorization, private UserDirectory $users, private ChatCursor $cursors) {}

    /** @return array<string,mixed> */
    public function directory(ActorContext $actor, string $query, PageRequest $page): array
    {
        $this->authorization->authorize($actor, 'collaboration.use');
        $position = $page->cursor ? $this->cursors->decode($actor, 'directory:'.hash('sha256', $query), $page->cursor) : ['id' => 0];
        $rows = User::query()->where('institution_id', $actor->institutionId)->where('status', 'active')->where('id', '!=', $actor->userId)
            ->where('id', '>', $position['id'])->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')->orderBy('id')->limit($page->limit + 1)->get();
        $result = [];
        foreach ($rows->take($page->limit) as $user) {
            if ($this->users->canContact($actor, (int) $user->id)) {
                $result[] = ['id' => $user->id, 'name' => $user->name];
            }
            $position['id'] = (int) $user->id;
        }

        return ['users' => $result, 'next_cursor' => $rows->count() > $page->limit ? $this->cursors->encode($actor, 'directory:'.hash('sha256', $query), $position) : null];
    }

    /** @return array<string,mixed> */
    public function conversations(ActorContext $actor, PageRequest $page): array
    {
        $this->authorization->authorize($actor, 'collaboration.use');
        $query = DB::table('collab_chat_conversations as c')->where('c.institution_id', $actor->institutionId)->where(function ($q) use ($actor): void {
            $q->whereExists(function ($p) use ($actor): void {
                $p->selectRaw('1')->from('collab_chat_participants as p')->whereColumn('p.conversation_id', 'c.id')
                    ->where('p.institution_id', $actor->institutionId)->where('p.user_id', $actor->userId)->where('p.active', true);
            })->orWhere(function ($standard) use ($actor): void {
                $standard->where('c.visibility', 'standard')->whereExists(function ($t) use ($actor): void {
                    $t->selectRaw('1')->from('collab_chat_team_members as t')->whereColumn('t.team_id', 'c.team_id')
                        ->where('t.institution_id', $actor->institutionId)->where('t.user_id', $actor->userId)->where('t.active', true);
                });
            });
        })->select('c.*');
        if ($page->cursor) {
            $position = $this->cursors->decode($actor, 'conversations', $page->cursor);
            $query->where(fn ($q) => $q->where('c.activity_at', '<', $position['at'])->orWhere(fn ($q) => $q->where('c.activity_at', $position['at'])->where('c.id', '<', $position['id'])));
        }
        $rows = $query->orderByDesc('c.activity_at')->orderByDesc('c.id')->limit($page->limit + 1)->get();
        $result = [];
        foreach ($rows->take($page->limit) as $row) {
            if ($this->access->membershipRow($row, $actor->userId)) {
                $result[] = $this->summary($actor, $row);
            }
        }
        $last = $rows->take($page->limit)->last();

        return ['conversations' => $result, 'next_cursor' => $rows->count() > $page->limit && $last ? $this->cursors->encode($actor, 'conversations', ['at' => $last->activity_at, 'id' => $last->id]) : null];
    }

    /** @return array<string,mixed> */
    public function conversation(ActorContext $actor, string $id): array
    {
        return $this->summary($actor, $this->access->conversation($actor, $id));
    }

    /** @return array<string,mixed> */
    private function summary(ActorContext $actor, object $row): array
    {
        $peer = $row->type === 'direct' ? User::query()->where('institution_id', $actor->institutionId)->findOrFail($this->access->peerId($row, $actor->userId)) : null;
        $from = $this->access->fromTick($actor, $row);
        $last = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $row->id)->where('access_tick', '>=', $from)->orderByDesc('sequence')->first();
        $unread = DB::table('collab_chat_messages as m')->leftJoin('collab_chat_receipts as r', function ($join) use ($actor): void {
            $join->on('r.message_id', '=', 'm.id')->where('r.user_id', $actor->userId);
        })->where('m.institution_id', $actor->institutionId)->where('m.conversation_id', $row->id)->where('m.access_tick', '>=', $from)->where('m.sender_id', '!=', $actor->userId)->whereNull('r.read_at')->count();

        return ['id' => $row->id, 'type' => $row->type, 'peer' => ['id' => $peer?->id, 'name' => $peer->name ?? $row->name],
            'name' => $row->name, 'description' => $row->description, 'team_id' => $row->team_id, 'visibility' => $row->visibility,
            'history_epoch' => hash('sha256', $row->id.':'.$actor->userId.':'.$from), 'archived' => (bool) $row->archived, 'role' => $this->access->membershipRow($row, $actor->userId)?->role,
            'member_count' => count($this->access->members($row)),
            'can_send' => $this->access->canSend($row, $actor->userId), 'unread_count' => $unread,
            'last_message' => $last ? $this->message($last, $actor) : null, 'activity_at' => $this->date($row->activity_at), 'version' => (int) $row->event_version];
    }

    /** @param list<string>|null $ids
     * @return array<string,mixed>
     */
    public function messages(ActorContext $actor, string $id, PageRequest $page, ?array $ids = null): array
    {
        $conversation = $this->access->conversation($actor, $id);
        $query = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $id)->where('access_tick', '>=', $this->access->fromTick($actor, $conversation));
        $mode = 'older';
        $position = 0;
        if ($page->cursor) {
            $state = $this->cursors->decode($actor, 'messages:'.$id, $page->cursor);
            $mode = $state['mode'];
            $position = (int) $state['sequence'];
            $query->where('sequence', $mode === 'newer' ? '>' : '<', $position);
        }
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $rows = ($mode === 'newer' ? $query->orderBy('sequence') : $query->orderByDesc('sequence'))->limit($page->limit + 1)->get();
        $slice = $rows->take($page->limit)->sortBy('sequence')->values();
        $min = $slice->first()->sequence ?? $position;
        $max = $slice->last()->sequence ?? $position;

        return ['messages' => $slice->map(fn ($row) => $this->message($row, $actor))->all(), 'has_more' => $rows->count() > $page->limit,
            'older_cursor' => $this->cursors->encode($actor, 'messages:'.$id, ['mode' => 'older', 'sequence' => (int) $min]),
            'newer_cursor' => $this->cursors->encode($actor, 'messages:'.$id, ['mode' => 'newer', 'sequence' => (int) $max])];
    }

    /** @return array<string,mixed> */
    private function message(object $row, ActorContext $actor): array
    {
        $receipts = DB::table('collab_chat_receipts')->where('institution_id', $row->institution_id)->where('message_id', $row->id);
        if ((int) $row->sender_id !== $actor->userId) {
            $receipts->where('user_id', $actor->userId);
        }
        $receipt = (clone $receipts)->orderByDesc('read_at')->first();
        $sender = User::query()->where('institution_id', $actor->institutionId)->find($row->sender_id);

        return ['id' => $row->id, 'conversation_id' => $row->conversation_id, 'sender_id' => (int) $row->sender_id,
            'sender_name' => $sender->name ?? '', 'delivered_count' => (clone $receipts)->count(), 'read_count' => (clone $receipts)->whereNotNull('read_at')->count(),
            'sequence' => (int) $row->sequence, 'command_id' => $row->command_id, 'body' => $row->body, 'created_at' => $this->date($row->created_at),
            'status' => $receipt?->read_at !== null ? 'read' : ($receipt ? 'delivered' : 'sent')];
    }

    private function date(string $value): string
    {
        return \Illuminate\Support\Carbon::parse($value, 'UTC')->toISOString();
    }
}
