<?php

declare(strict_types=1);

namespace Modules\TeamChat\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\ChatQueries;

final class ChatController
{
    public function __construct(private ChatCommands $commands, private ChatQueries $queries) {}

    public function directory(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => 'sometimes|nullable|string|max:100']);

        return $this->response($request, $this->queries->directory($this->actor($request), trim($input['q'] ?? ''), $this->page($request)));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->response($request, $this->queries->conversations($this->actor($request), $this->page($request)));
    }

    public function direct(Request $request): JsonResponse
    {
        $input = $request->validate(['participant_id' => 'required|integer|min:1', 'institution_id' => 'prohibited', 'sender_id' => 'prohibited', 'participants' => 'prohibited']);
        $id = $this->commands->direct($this->actor($request), (int) $input['participant_id']);

        return $this->response($request, $this->queries->conversation($this->actor($request), $id));
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        return $this->response($request, $this->queries->conversation($this->actor($request), $conversation));
    }

    public function messages(Request $request, string $conversation): JsonResponse
    {
        $input = $request->validate(['ids' => 'sometimes|array|min:1|max:50', 'ids.*' => 'required|ulid|distinct']);

        return $this->response($request, $this->queries->messages($this->actor($request), $conversation, $this->page($request), $input['ids'] ?? null));
    }

    public function send(Request $request, string $conversation): JsonResponse
    {
        $input = $request->validate(['command_id' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._:-]+$/D'], 'body' => 'sometimes|nullable|string|max:5000', 'attachments' => 'sometimes|array|max:'.config('teamchat.attachments.max_count'), 'attachments.*' => 'required|ulid|distinct',
            'sender_id' => 'prohibited', 'user_id' => 'prohibited', 'institution_id' => 'prohibited', 'sequence' => 'prohibited', 'participants' => 'prohibited']);
        $id = $this->commands->send($this->actor($request), $conversation, $input['command_id'], $input['body'] ?? '', $input['attachments'] ?? []);
        $result = $this->queries->messages($this->actor($request), $conversation, new PageRequest(null, 1), [$id]);

        return $this->response($request, $result['messages'][0]);
    }

    public function receipt(Request $request, string $conversation): JsonResponse
    {
        $input = $request->validate(['message_ids' => 'required|array|min:1|max:50', 'message_ids.*' => 'required|ulid|distinct', 'kind' => 'required|in:delivered,read']);
        $this->commands->acknowledge($this->actor($request), $conversation, $input['message_ids'], $input['kind'] === 'read');

        return $this->response($request, ['acknowledged' => true]);
    }

    public function membership(Request $request, string $conversation): JsonResponse
    {
        $input = $request->validate(['active' => 'required|boolean', 'user_id' => 'prohibited', 'participant_id' => 'prohibited']);
        $this->commands->membership($this->actor($request), $conversation, (bool) $input['active']);

        return $this->response($request, ['active' => (bool) $input['active']]);
    }

    private function page(Request $request): PageRequest
    {
        $input = $request->validate(['cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:50']);

        return new PageRequest($input['cursor'] ?? null, (int) ($input['limit'] ?? 30));
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new ContractException('unauthenticated', 401);
        }

        return $actor;
    }

    /** @param array<string,mixed> $data */
    private function response(Request $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $this->actor($request)->requestId, 'version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }
}
