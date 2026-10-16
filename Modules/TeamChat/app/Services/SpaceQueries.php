<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;

final class SpaceQueries
{
    public function __construct(private ChatAccess $access, private CollaborationAccess $authorization, private ChatCursor $cursors, private ChatQueries $chats) {}

    /** @return array<string,mixed> */
    public function teams(ActorContext $actor, PageRequest $page): array
    {
        $this->authorization->authorize($actor, 'collaboration.use');
        $position = $page->cursor ? $this->cursors->decode($actor, 'teams', $page->cursor)['id'] : '';
        $rows = DB::table('collab_chat_teams as t')->join('collab_chat_team_members as m', 'm.team_id', '=', 't.id')
            ->where('t.institution_id', $actor->institutionId)->where('m.institution_id', $actor->institutionId)->where('m.user_id', $actor->userId)
            ->where('m.active', true)->where('t.id', '>', $position)->orderBy('t.id')->limit($page->limit + 1)->select('t.id')->get();
        $slice = $rows->take($page->limit);

        return ['teams' => $slice->map(fn ($row) => $this->team($actor, $row->id))->all(),
            'next_cursor' => $rows->count() > $page->limit ? $this->cursors->encode($actor, 'teams', ['id' => $slice->last()->id]) : null];
    }

    /** @return array<string,mixed> */
    public function team(ActorContext $actor, string $id): array
    {
        $row = $this->access->team($actor, $id);
        $role = $this->access->teamMembers($actor->institutionId, $id)->where('user_id', $actor->userId)->value('role');

        return ['id' => $id, 'name' => $row->name, 'description' => $row->description, 'archived' => (bool) $row->archived, 'role' => $role,
            'general_id' => DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('team_id', $id)->where('default_key', 'general')->value('id')];
    }

    /** @return array<string,mixed> */
    public function channels(ActorContext $actor, string $id, PageRequest $page): array
    {
        $this->access->team($actor, $id);
        $position = $page->cursor ? $this->cursors->decode($actor, 'channels:'.$id, $page->cursor)['id'] : '';
        $rows = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('team_id', $id)->where('id', '>', $position)
            ->where(function ($q) use ($actor): void {
                $q->where('visibility', 'standard')->orWhereExists(function ($p) use ($actor): void {
                    $p->selectRaw('1')->from('collab_chat_participants as p')->whereColumn('p.conversation_id', 'collab_chat_conversations.id')
                        ->where('p.institution_id', $actor->institutionId)->where('p.user_id', $actor->userId)->where('p.active', true);
                });
            })->orderBy('id')->limit($page->limit + 1)->get();
        $slice = $rows->take($page->limit);

        return ['channels' => $slice->map(fn ($row) => $this->chats->conversation($actor, $row->id))->all(),
            'next_cursor' => $rows->count() > $page->limit ? $this->cursors->encode($actor, 'channels:'.$id, ['id' => $slice->last()->id]) : null];
    }

    /** @return array<string,mixed> */
    public function members(ActorContext $actor, string $scope, string $id): array
    {
        $team = $scope === 'team';
        $row = $team ? $this->access->team($actor, $id) : $this->access->conversation($actor, $id);
        $derived = ! $team && $row->team_id && $row->visibility === 'standard';
        $query = $team || $derived ? $this->access->teamMembers($actor->institutionId, $team ? $id : $row->team_id) : $this->access->participants($actor->institutionId, $id);
        $result = [];
        foreach ($query->where('active', true)->orderBy('user_id')->get() as $member) {
            $user = User::query()->where('institution_id', $actor->institutionId)->find($member->user_id);
            $result[] = ['id' => (int) $member->user_id, 'name' => $user->name ?? '', 'role' => $member->role,
                'active' => $this->access->userActive($actor->institutionId, (int) $member->user_id), 'generation' => (int) $member->generation];
        }

        return ['members' => $result, 'derived' => (bool) $derived];
    }

    /** @return array<string,mixed> */
    public function history(ActorContext $actor, string $scope, string $id, PageRequest $page): array
    {
        if (! in_array($scope, ['team', 'conversation'], true)) {
            throw new ContractException('invalid', 422);
        }
        $row = $scope === 'team' ? $this->access->team($actor, $id) : $this->access->conversation($actor, $id);
        $member = $scope === 'team' ? $this->access->teamMembers($actor->institutionId, $id)->where('user_id', $actor->userId)->firstOrFail() : $this->access->membershipRow($row, $actor->userId);
        $position = $page->cursor ? $this->cursors->decode($actor, 'members:'.$scope.':'.$id, $page->cursor)['id'] : 0;
        $rows = DB::table('collab_chat_membership_history')->where('institution_id', $actor->institutionId)->where('scope', $scope)->where('resource_id', $id)
            ->where('tick', '>=', $member->from_tick)->where('id', '>', $position)->orderBy('id')->limit($page->limit + 1)->get(['id', 'user_id', 'actor_id', 'role', 'active', 'tick', 'created_at']);
        $slice = $rows->take($page->limit);

        return ['changes' => $slice->map(function ($change) use ($actor): array {
            $user = User::query()->where('institution_id', $actor->institutionId)->find($change->user_id);

            return (array) $change + ['user_name' => $user->name ?? ''];
        })->all(), 'next_cursor' => $rows->count() > $page->limit ? $this->cursors->encode($actor, 'members:'.$scope.':'.$id, ['id' => $slice->last()->id]) : null];
    }
}
