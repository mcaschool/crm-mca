<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\ApplicationAccess;

final class ChatAccess
{
    public function __construct(private CollaborationAccess $access, private ApplicationAccess $applications) {}

    public function conversation(ActorContext $actor, string $id, bool $sending = false): object
    {
        $this->access->authorize($actor, 'collaboration.use');
        $row = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $id)->first();
        if (! $row || ! $this->membershipRow($row, $actor->userId)) {
            throw new ContractException('not_found', 404);
        }
        if ($sending && ! $this->canSend($row, $actor->userId)) {
            throw new ContractException('forbidden', 403);
        }

        return $row;
    }

    public function canSend(object $row, int $userId): bool
    {
        if ($row->archived) {
            return false;
        }
        if ($row->type === 'direct') {
            return $this->memberActive((int) $row->institution_id, $row->id, $this->peerId($row, $userId));
        }
        $scope = $row->team_id ? $this->teamMembers((int) $row->institution_id, $row->team_id) : $this->participants((int) $row->institution_id, $row->id);
        $owner = $scope->where('role', 'owner')->where('active', true)->first();
        if ($row->team_id && $row->visibility === 'private') {
            $privateOwner = $this->participants((int) $row->institution_id, $row->id)->where('role', 'owner')->where('active', true)->first();
            if (! $privateOwner || ! $this->memberActive((int) $row->institution_id, $row->id, (int) $privateOwner->user_id)) {
                return false;
            }
        }
        $team = $row->team_id ? DB::table('collab_chat_teams')->where('id', $row->team_id)->first() : null;

        return (! $team || ! $team->archived) && $owner !== null && $this->userActive((int) $row->institution_id, (int) $owner->user_id);
    }

    public function userActive(int $institutionId, int $userId): bool
    {
        $user = User::query()->where('institution_id', $institutionId)->find($userId);

        return $user !== null && $this->applications->allows($user, 'collaboration');
    }

    public function memberActive(int $institutionId, string $conversationId, int $userId): bool
    {
        $row = DB::table('collab_chat_conversations')->where('institution_id', $institutionId)->where('id', $conversationId)->first();

        return $row !== null && $this->userActive($institutionId, $userId) && $this->membershipRow($row, $userId) !== null;
    }

    public function membershipRow(object $row, int $userId): ?object
    {
        $teamMember = null;
        if ($row->team_id !== null) {
            $teamMember = $this->teamMembers((int) $row->institution_id, $row->team_id)->where('user_id', $userId)->where('active', true)->first();
            if (! $teamMember) {
                return null;
            }
            if ($row->visibility === 'standard') {
                return $teamMember;
            }
        }
        $member = $this->participants((int) $row->institution_id, $row->id)->where('user_id', $userId)->where('active', true)->first();
        if ($member && $teamMember) {
            $member->from_tick = max((int) $member->from_tick, (int) $teamMember->from_tick);
        }

        return $member;
    }

    public function fromTick(ActorContext $actor, object $row): int
    {
        $member = $this->membershipRow($row, $actor->userId);
        if (! $member) {
            throw new ContractException('not_found', 404);
        }

        return (int) $member->from_tick;
    }

    /** @return list<int> */
    public function members(object $row): array
    {
        $query = $row->team_id && $row->visibility === 'standard'
            ? $this->teamMembers((int) $row->institution_id, $row->team_id)
            : $this->participants((int) $row->institution_id, $row->id);

        return $query->where('active', true)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $this->memberActive((int) $row->institution_id, $row->id, $id))->values()->all();
    }

    public function team(ActorContext $actor, string $id): object
    {
        $this->access->authorize($actor, 'collaboration.use');
        $row = DB::table('collab_chat_teams')->where('institution_id', $actor->institutionId)->where('id', $id)->first();
        if (! $row || ! $this->teamMembers($actor->institutionId, $id)->where('user_id', $actor->userId)->where('active', true)->exists()) {
            throw new ContractException('not_found', 404);
        }

        return $row;
    }

    public function participants(int $institutionId, string $id): Builder
    {
        return DB::table('collab_chat_participants')->where('institution_id', $institutionId)->where('conversation_id', $id);
    }

    public function teamMembers(int $institutionId, string $id): Builder
    {
        return DB::table('collab_chat_team_members')->where('institution_id', $institutionId)->where('team_id', $id);
    }

    public function peerId(object $conversation, int $userId): int
    {
        return (int) $conversation->first_user_id === $userId ? (int) $conversation->second_user_id : (int) $conversation->first_user_id;
    }
}
