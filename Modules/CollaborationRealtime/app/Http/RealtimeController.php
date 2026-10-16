<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Http;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Services\DeviceChannels;

final class RealtimeController
{
    public function sync(Request $request, SyncFeed $feed): JsonResponse
    {
        $input = $request->validate(['cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:100', 'bootstrap' => 'sometimes|boolean']);
        $actor = $this->actor($request);
        $page = new PageRequest($input['cursor'] ?? null, (int) ($input['limit'] ?? 20));
        $result = $request->boolean('bootstrap') ? $feed->bootstrap($actor, $page) : $feed->read($actor, $page);

        return response()->json(['data' => $result, 'meta' => ['request_id' => $actor->requestId, 'version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }

    public function channel(Request $request, DeviceChannels $channels): JsonResponse
    {
        return response()->json(['data' => ['channel' => $channels->name($this->actor($request)), 'event' => 'collaboration.sync.required']], 200, ['Cache-Control' => 'no-store']);
    }

    public function authorize(Request $request, DeviceChannels $channels, BroadcastManager $broadcast): JsonResponse
    {
        $input = $request->validate(['socket_id' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+$/D', 'max:80'], 'channel_name' => 'required|string|max:128']);
        if (! hash_equals($channels->name($this->actor($request)), $input['channel_name'])) {
            throw new ContractException('forbidden', 403);
        }
        // Laravel's private-channel response signs this socket and this server-derived device channel.
        $response = $broadcast->connection('reverb')->validAuthenticationResponse($request, true);

        return response()->json($response, 200, ['Cache-Control' => 'no-store']);
    }

    public function signals(Request $request, \Modules\CollaborationRealtime\Services\CurrentSignals $signals): JsonResponse
    {
        $input = $request->validate(['cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:100']);
        $actor = $this->actor($request);

        return response()->json(['data' => $signals->response($actor, new PageRequest($input['cursor'] ?? null, (int) ($input['limit'] ?? 20))), 'meta' => ['request_id' => $actor->requestId, 'version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }

    public function notifications(Request $request, \Modules\CollaborationRealtime\Services\NotificationInbox $inbox): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(['data' => $inbox->current($actor), 'meta' => ['request_id' => $actor->requestId, 'version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new ContractException('unauthenticated', 401);
        }

        return $actor;
    }
}
