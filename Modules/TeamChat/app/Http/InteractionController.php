<?php

declare(strict_types=1);

namespace Modules\TeamChat\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamChat\Services\ChatAccess;
use Modules\TeamChat\Services\ChatCursor;
use Modules\TeamChat\Services\ChatQueries;
use Modules\TeamChat\Services\MessageInteractions;

final class InteractionController
{
    public function change(Request $r, string $conversation, string $message, string $operation): JsonResponse
    {
        $data = $r->validate(['command_id' => 'required|string|max:64', 'version' => 'sometimes|integer|min:1', 'body' => 'sometimes|string|max:5000', 'emoji' => 'sometimes|string|max:32', 'user_id' => 'prohibited', 'institution_id' => 'prohibited']);
        app(MessageInteractions::class)->change($this->actor($r), $conversation, $message, $data['command_id'], $operation, array_diff_key($data, ['command_id' => true]));

        return $this->reply($r, app(ChatQueries::class)->messages($this->actor($r), $conversation, new PageRequest(null, 1), [$message])['messages'][0]);
    }

    public function search(Request $r, string $conversation): JsonResponse
    {
        $data = $r->validate(['q' => 'required|string|min:2|max:100', 'cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:50']);
        $actor = $this->actor($r);
        $access = app(ChatAccess::class);
        $space = $access->conversation($actor, $conversation);
        $from = $access->fromTick($actor, $space);
        $term = trim($data['q']);
        if (mb_strlen($term) < 2) {
            throw new ContractException('invalid', 422);
        }
        $purpose = 'search:'.$conversation.':'.$from.':'.hash('sha256', $term);
        $cursor = isset($data['cursor']) ? app(ChatCursor::class)->decode($actor, $purpose, $data['cursor']) : null;
        $limit = (int) ($data['limit'] ?? 20);
        $query = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $conversation)->where('access_tick', '>=', $from)->whereNull('deleted_at');
        if ($cursor) {
            $query->where('sequence', '<', (int) $cursor['sequence']);
        }
        // Bound each scan to 5000 authorized messages; the encrypted cursor can continue through older windows.
        $window = (clone $query)->orderByDesc('sequence')->limit(5000)->pluck('sequence');
        $floor = $window->last();
        if ($floor !== null) {
            $query->where('sequence', '>=', $floor);
        }
        $rows = $query->where('body', 'like', '%'.addcslashes($term, '%_\\').'%')->orderByDesc('sequence')->limit($limit + 1)->get();
        $slice = $rows->take($limit);
        $last = $slice->last();

        return $this->reply($r, ['messages' => $slice->map(fn ($row) => app(ChatQueries::class)->message($row, $actor))->all(),
            'next_cursor' => $rows->count() > $limit && $last ? app(ChatCursor::class)->encode($actor, $purpose, ['sequence' => (int) $last->sequence]) : ($window->count() === 5000 ? app(ChatCursor::class)->encode($actor, $purpose, ['sequence' => (int) $floor]) : null)]);
    }

    public function pins(Request $r, string $conversation): JsonResponse
    {
        $actor = $this->actor($r);
        $access = app(ChatAccess::class);
        $space = $access->conversation($actor, $conversation);
        $rows = DB::table('collab_chat_messages as m')->join('collab_chat_pins as p', 'p.message_id', '=', 'm.id')
            ->where('m.institution_id', $actor->institutionId)->where('m.conversation_id', $conversation)->where('m.access_tick', '>=', $access->fromTick($actor, $space))->whereNull('m.deleted_at')->orderByDesc('m.sequence')->select('m.*')->limit((int) config('teamchat.interactions.pin_limit'))->get();

        return $this->reply($r, ['messages' => $rows->map(fn ($row) => app(ChatQueries::class)->message($row, $actor))->all()]);
    }

    public function members(Request $r, string $conversation): JsonResponse
    {
        $data = $r->validate(['q' => 'sometimes|nullable|string|max:100', 'cursor' => 'sometimes|string|max:4096']);
        $actor = $this->actor($r);
        $access = app(ChatAccess::class);
        $space = $access->conversation($actor, $conversation);
        $term = trim($data['q'] ?? '');
        $purpose = 'mentions:'.$conversation.':'.hash('sha256', $term);
        $after = isset($data['cursor']) ? (int) app(ChatCursor::class)->decode($actor, $purpose, $data['cursor'])['id'] : 0;
        $rows = \App\Models\User::query()->where('institution_id', $actor->institutionId)->whereIn('id', $access->members($space))->where('id', '>', $after)->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')->orderBy('id')->limit(21)->get();
        $slice = $rows->take(20);

        return $this->reply($r, ['users' => $slice->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(), 'next_cursor' => $rows->count() > 20 ? app(ChatCursor::class)->encode($actor, $purpose, ['id' => (int) $slice->last()->id]) : null]);
    }

    public function mentionTarget(Request $r, string $conversation, string $event): JsonResponse
    {
        $actor = $this->actor($r);
        $row = DB::table('collab_events')->where('institution_id', $actor->institutionId)->where('aggregate_id', $conversation)->where('id', $event)->first();
        if (! $row || ! app(\Modules\CollaborationCore\Services\NotificationPreferences::class)->enabled($actor)) {
            throw new ContractException('not_found', 404);
        }
        $delivery = app(\Modules\CollaborationRealtime\Services\EventCodec::class)->decode($row);
        if ($delivery->event->type !== 'teamchat.message.mentioned' || ! app(\Modules\CollaborationRealtime\Services\EventAccess::class)->allows($actor, $delivery)) {
            throw new ContractException('not_found', 404);
        }
        $message = app(MessageInteractions::class)->message($actor, $conversation, $delivery->event->data['message_id']);

        return $this->reply($r, app(ChatQueries::class)->message($message, $actor));
    }

    private function actor(Request $r): ActorContext
    {
        $actor = $r->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new ContractException('unauthenticated', 401);
        }

        return $actor;
    }

    /** @param array<string,mixed> $data */
    private function reply(Request $r, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['version' => '1.0', 'request_id' => $this->actor($r)->requestId]], 200, ['Cache-Control' => 'no-store']);
    }
}
