<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;

final class SpaceCommands
{
    public function __construct(private ChatAccess $access, private CollaborationAccess $authorization, private UserDirectory $users,
        private ChatClock $clock, private ChatEvents $events) {}

    /** @param list<int> $members */
    public function create(ActorContext $actor, string $kind, string $commandId, string $name, string $description = '', array $members = [], ?string $teamId = null, string $visibility = 'private'): string
    {
        $this->metadata($name, $description);
        if (! in_array($kind, ['group', 'team', 'channel'], true) || ! in_array($visibility, ['standard', 'private'], true)
            || ! array_is_list($members) || count(array_unique($members)) !== count($members) || count($members) > 99) {
            throw new ContractException('invalid', 422);
        }
        foreach ($members as $id) {
            if (! is_int($id) || $id < 1 || $id === $actor->userId) {
                throw new ContractException('invalid', 422);
            }
        }
        if ($kind === 'group' && count($members) < 2) {
            throw new ContractException('invalid', 422);
        }

        return DB::transaction(function () use ($actor, $kind, $commandId, $name, $description, $members, $teamId, $visibility): string {
            $this->lock($actor);
            if ($kind === 'channel') {
                $team = $this->access->team($actor, $teamId ?? '');
                $this->manage($this->access->teamMembers($actor->institutionId, $team->id), $actor);
                if ($team->archived) {
                    throw new ContractException('forbidden', 403);
                }
            }
            $hash = $this->hash([$kind, $name, $description, $members, $teamId, $visibility]);
            if ($id = $this->replay($actor, $commandId, $hash)) {
                $kind === 'team' ? $this->access->team($actor, $id) : $this->access->conversation($actor, $id);

                return $id;
            }
            foreach ($members as $userId) {
                $this->users->resolve($actor, $userId);
                if ($kind === 'channel' && ! $this->access->teamMembers($actor->institutionId, $teamId ?? '')->where('user_id', $userId)->where('active', true)->exists()) {
                    throw new ContractException('not_found', 404);
                }
            }
            $tick = $this->clock->next($actor->institutionId);
            $id = (string) Str::ulid();
            if ($kind === 'team') {
                DB::table('collab_chat_teams')->insert(['id' => $id, 'institution_id' => $actor->institutionId, 'name' => trim($name), 'description' => trim($description), 'created_at' => now(), 'updated_at' => now()]);
                foreach ([$actor->userId, ...$members] as $userId) {
                    $this->join($actor, 'team', $id, $userId, $userId === $actor->userId ? 'owner' : 'member', $tick);
                }
                $general = $this->conversation($actor, 'channel', 'General', '', $id, 'standard', 'general');
                $this->events->record($actor, $general, 'teamchat.team.created', []);
            } else {
                $row = $this->conversation($actor, $kind, $name, $description, $kind === 'channel' ? $teamId : null, $kind === 'channel' ? $visibility : 'private', null, $id);
                if ($kind === 'group' || $visibility === 'private') {
                    foreach ([$actor->userId, ...$members] as $userId) {
                        $this->join($actor, 'conversation', $id, $userId, $userId === $actor->userId ? 'owner' : 'member', $tick);
                    }
                }
                $this->events->record($actor, $row, 'teamchat.'.$kind.'.created', []);
            }
            $this->remember($actor, $commandId, $hash, $id);

            return $id;
        }, 3);
    }

    public function update(ActorContext $actor, string $scope, string $id, string $commandId, string $name, string $description, bool $archived): void
    {
        $this->metadata($name, $description);
        DB::transaction(function () use ($actor, $scope, $id, $commandId, $name, $description, $archived): void {
            $this->lock($actor);
            $row = $this->resource($actor, $scope, $id);
            $this->manage($this->roster($actor, $scope, $row), $actor);
            if ($scope !== 'team' && $row->default_key === 'general' && $archived) {
                throw new ContractException('forbidden', 403);
            }
            $hash = $this->hash(['update', $scope, $id, $name, $description, $archived]);
            if ($this->replay($actor, $commandId, $hash)) {
                return;
            }
            DB::table($scope === 'team' ? 'collab_chat_teams' : 'collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $id)
                ->update(['name' => trim($name), 'description' => trim($description), 'archived' => $archived, 'access_version' => DB::raw('access_version + 1'), 'updated_at' => now()]);
            $this->changed($actor, $scope, $row, false);
            $this->remember($actor, $commandId, $hash, $id);
        }, 3);
    }

    public function member(ActorContext $actor, string $scope, string $id, string $commandId, int $userId, string $operation, string $role = 'member'): void
    {
        if (! in_array($operation, ['add', 'remove', 'role', 'transfer'], true) || ! in_array($role, ['admin', 'member'], true) || $userId < 1) {
            throw new ContractException('invalid', 422);
        }
        DB::transaction(function () use ($actor, $scope, $id, $commandId, $userId, $operation, $role): void {
            $this->lock($actor);
            $row = $this->resource($actor, $scope, $id);
            if ($scope !== 'team' && $row->type === 'channel' && $row->visibility === 'standard') {
                throw new ContractException('forbidden', 403);
            }
            $roster = $this->roster($actor, $scope, $row);
            $hash = $this->hash(['member', $scope, $id, $userId, $operation, $role]);
            if ($this->replay($actor, $commandId, $hash)) {
                return;
            }
            $self = (clone $roster)->where('user_id', $actor->userId)->where('active', true)->firstOrFail();
            $target = (clone $roster)->where('user_id', $userId)->first();
            if ($operation !== 'remove' || $userId !== $actor->userId) {
                $this->manage(clone $roster, $actor);
            }
            if (in_array($operation, ['role', 'transfer'], true) && $self->role !== 'owner') {
                throw new ContractException('forbidden', 403);
            }
            if ($target && $target->active && $target->role === 'owner') {
                throw new ContractException('forbidden', 403);
            }
            if ($target && $target->active && $target->role === 'admin' && $self->role !== 'owner' && $userId !== $actor->userId) {
                throw new ContractException('forbidden', 403);
            }
            $tick = $this->clock->next($actor->institutionId);
            if ($operation === 'add') {
                $this->users->resolve($actor, $userId);
                if ($row->archived || (($scope !== 'team') && $row->team_id && ! $this->access->teamMembers($actor->institutionId, $row->team_id)->where('user_id', $userId)->where('active', true)->exists())) {
                    throw new ContractException('forbidden', 403);
                }
                if (! $target || ! $target->active) {
                    if ((clone $roster)->where('active', true)->count() >= 100) {
                        throw new ContractException('invalid', 422);
                    }
                    $this->join($actor, $scope, $id, $userId, 'member', $tick);
                }
            } else {
                if (! $target || ! $target->active) {
                    throw new ContractException('not_found', 404);
                }
                if ($operation === 'transfer') {
                    $this->users->resolve($actor, $userId);
                    (clone $roster)->where('user_id', $actor->userId)->update(['role' => 'admin', 'updated_at' => now()]);
                    $this->audit($actor, $scope, $id, $actor->userId, 'admin', true, $tick);
                }
                $newRole = $operation === 'transfer' ? 'owner' : ($operation === 'role' ? $role : $target->role);
                (clone $roster)->where('user_id', $userId)->update(['role' => $newRole, 'active' => $operation !== 'remove', 'updated_at' => now()]);
                $this->audit($actor, $scope, $id, $userId, $newRole, $operation !== 'remove', $tick);
                if ($scope === 'team' && $operation === 'remove') {
                    // A team rejoin must never silently restore admission to private channels.
                    $channels = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('team_id', $id)->where('visibility', 'private')->get();
                    foreach ($channels as $channel) {
                        $member = $this->access->participants($actor->institutionId, $channel->id)->where('user_id', $userId)->where('active', true)->first();
                        if ($member) {
                            $this->access->participants($actor->institutionId, $channel->id)->where('user_id', $userId)->update(['active' => false, 'updated_at' => now()]);
                            $this->audit($actor, 'conversation', $channel->id, $userId, $member->role, false, $tick);
                            if ($member->role === 'owner') {
                                DB::table('collab_chat_conversations')->where('id', $channel->id)->update(['archived' => true]);
                            }
                        }
                    }
                }
            }
            DB::table($scope === 'team' ? 'collab_chat_teams' : 'collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $id)->increment('access_version');
            $this->changed($actor, $scope, $row, true);
            $this->remember($actor, $commandId, $hash, $id);
        }, 3);
    }

    private function lock(ActorContext $actor): void
    {
        DB::table('institutions')->where('id', $actor->institutionId)->lockForUpdate()->firstOrFail();
        $this->authorization->authorize($actor, 'collaboration.use');
    }

    private function resource(ActorContext $actor, string $scope, string $id): object
    {
        if (! in_array($scope, ['team', 'conversation'], true)) {
            throw new ContractException('invalid', 422);
        }
        $row = $scope === 'team' ? $this->access->team($actor, $id) : $this->access->conversation($actor, $id);
        if ($scope !== 'team' && $row->type === 'direct') {
            throw new ContractException('forbidden', 403);
        }

        return $row;
    }

    private function roster(ActorContext $actor, string $scope, object $row): Builder
    {
        return $scope === 'team' || ($row->type === 'channel' && $row->visibility === 'standard')
            ? $this->access->teamMembers($actor->institutionId, $scope === 'team' ? $row->id : $row->team_id)
            : $this->access->participants($actor->institutionId, $row->id);
    }

    private function manage(Builder $roster, ActorContext $actor): void
    {
        if (! $roster->where('user_id', $actor->userId)->where('active', true)->whereIn('role', ['owner', 'admin'])->exists()) {
            throw new ContractException('forbidden', 403);
        }
    }

    private function join(ActorContext $actor, string $scope, string $id, int $userId, string $role, int $tick): void
    {
        $team = $scope === 'team';
        $query = $team ? $this->access->teamMembers($actor->institutionId, $id) : $this->access->participants($actor->institutionId, $id);
        $previous = $query->where('user_id', $userId)->first();
        DB::table($team ? 'collab_chat_team_members' : 'collab_chat_participants')->updateOrInsert(
            ['institution_id' => $actor->institutionId, $team ? 'team_id' : 'conversation_id' => $id, 'user_id' => $userId],
            ['active' => true, 'role' => $role, 'from_tick' => $tick, 'generation' => (int) ($previous->generation ?? 0) + 1, 'created_at' => $previous->created_at ?? now(), 'updated_at' => now()]);
        $this->audit($actor, $scope, $id, $userId, $role, true, $tick);
    }

    private function audit(ActorContext $actor, string $scope, string $id, int $userId, string $role, bool $active, int $tick): void
    {
        DB::table('collab_chat_membership_history')->insert(['institution_id' => $actor->institutionId, 'scope' => $scope, 'resource_id' => $id,
            'user_id' => $userId, 'actor_id' => $actor->userId, 'tick' => $tick, 'role' => $role, 'active' => $active, 'created_at' => now()]);
    }

    private function conversation(ActorContext $actor, string $type, string $name, string $description, ?string $team, string $visibility, ?string $default, ?string $id = null): object
    {
        $id ??= (string) Str::ulid();
        DB::table('collab_chat_conversations')->insert(['id' => $id, 'institution_id' => $actor->institutionId, 'type' => $type,
            'name' => trim($name), 'description' => trim($description), 'team_id' => $team, 'visibility' => $visibility, 'default_key' => $default,
            'activity_at' => now()->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('collab_chat_conversations')->where('id', $id)->firstOrFail();
    }

    private function changed(ActorContext $actor, string $scope, object $row, bool $membership): void
    {
        $channels = $scope === 'team'
            ? DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('team_id', $row->id)->get()->all()
            : [$row];
        foreach ($channels as $channel) {
            if ($scope === 'team') {
                DB::table('collab_chat_conversations')->where('id', $channel->id)->increment('access_version');
            }
            $type = $scope === 'team' ? 'team' : $channel->type;
            $this->events->record($actor, $channel, 'teamchat.'.$type.($membership ? '.members.changed' : '.updated'), []);
        }
    }

    private function metadata(string $name, string $description): void
    {
        if (trim($name) === '' || mb_strlen($name) > 120 || mb_strlen($description) > 1000 || str_contains($name.$description, "\0")) {
            throw new ContractException('invalid', 422);
        }
    }

    /** @param array<mixed> $values */
    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function replay(ActorContext $actor, string $commandId, string $hash): ?string
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $commandId) !== 1) {
            throw new ContractException('invalid', 422);
        }
        $row = DB::table('collab_chat_space_commands')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->where('command_id', $commandId)->first();
        if ($row && ! hash_equals($row->request_hash, $hash)) {
            throw new ContractException('idempotency_conflict', 409);
        }

        return $row?->resource_id;
    }

    private function remember(ActorContext $actor, string $commandId, string $hash, string $id): void
    {
        DB::table('collab_chat_space_commands')->insert(['institution_id' => $actor->institutionId, 'user_id' => $actor->userId, 'command_id' => $commandId, 'request_hash' => $hash, 'resource_id' => $id]);
    }
}
