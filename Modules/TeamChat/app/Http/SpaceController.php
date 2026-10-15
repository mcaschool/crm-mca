<?php

declare(strict_types=1);

namespace Modules\TeamChat\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\TeamChat\Services\ChatQueries;
use Modules\TeamChat\Services\SpaceCommands;
use Modules\TeamChat\Services\SpaceQueries;

final class SpaceController
{
    public function __construct(private SpaceCommands $commands, private SpaceQueries $queries, private ChatQueries $chats) {}

    public function create(Request $request): JsonResponse
    {
        $i = $request->validate(['kind' => 'required|in:group,team,channel', 'command_id' => 'required|string|max:64', 'name' => 'required|string|max:120',
            'description' => 'sometimes|nullable|string|max:1000', 'members' => 'sometimes|array|max:99', 'members.*' => 'integer|min:1|distinct',
            'team_id' => 'sometimes|nullable|ulid', 'visibility' => 'sometimes|in:private,standard', 'institution_id' => 'prohibited', 'owner_id' => 'prohibited', 'role' => 'prohibited']);
        $id = $this->commands->create($this->actor($request), $i['kind'], $i['command_id'], $i['name'], $i['description'] ?? '', array_map(intval(...), $i['members'] ?? []), $i['team_id'] ?? null, $i['visibility'] ?? 'private');

        return $this->response($request, $i['kind'] === 'team' ? $this->queries->team($this->actor($request), $id) : $this->chats->conversation($this->actor($request), $id));
    }

    public function teams(Request $r): JsonResponse
    {
        return $this->response($r, $this->queries->teams($this->actor($r), $this->page($r)));
    }

    public function team(Request $r, string $team): JsonResponse
    {
        return $this->response($r, $this->queries->team($this->actor($r), $team));
    }

    public function channels(Request $r, string $team): JsonResponse
    {
        return $this->response($r, $this->queries->channels($this->actor($r), $team, $this->page($r)));
    }

    public function members(Request $r, string $scope, string $id): JsonResponse
    {
        return $this->response($r, $this->queries->members($this->actor($r), $scope, $id));
    }

    public function history(Request $r, string $scope, string $id): JsonResponse
    {
        return $this->response($r, $this->queries->history($this->actor($r), $scope, $id, $this->page($r)));
    }

    public function update(Request $r, string $scope, string $id): JsonResponse
    {
        $i = $r->validate(['command_id' => 'required|string|max:64', 'name' => 'required|string|max:120', 'description' => 'present|nullable|string|max:1000',
            'archived' => 'required|boolean', 'visibility' => 'prohibited', 'institution_id' => 'prohibited', 'owner_id' => 'prohibited']);
        $this->commands->update($this->actor($r), $scope, $id, $i['command_id'], $i['name'], $i['description'] ?? '', (bool) $i['archived']);

        return $this->response($r, ['updated' => true]);
    }

    public function member(Request $r, string $scope, string $id): JsonResponse
    {
        $i = $r->validate(['command_id' => 'required|string|max:64', 'user_id' => 'required|integer|min:1', 'operation' => 'required|in:add,remove,role,transfer', 'role' => 'sometimes|in:admin,member', 'institution_id' => 'prohibited']);
        $this->commands->member($this->actor($r), $scope, $id, $i['command_id'], (int) $i['user_id'], $i['operation'], $i['role'] ?? 'member');

        return $this->response($r, ['updated' => true]);
    }

    private function actor(Request $r): ActorContext
    {
        return $r->attributes->get('collaboration.actor');
    }

    private function page(Request $r): PageRequest
    {
        $i = $r->validate(['cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:50']);

        return new PageRequest($i['cursor'] ?? null, (int) ($i['limit'] ?? 30));
    }

    /** @param array<string,mixed> $data */
    private function response(Request $r, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $this->actor($r)->requestId, 'version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }
}
