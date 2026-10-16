<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallSummary;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\CursorPage;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Data\ParticipantSummary;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;

/**
 * API DEFINITIVA de llamadas con las convenciones de CollaborationCore (/api/v1/collaboration/calls):
 * sesión + CSRF + MFA y dispositivo de Core (CollaborationRequest), respuestas `{data, meta}` y errores
 * `{error: {code, message, retryable, request_id}}` del renderizador de Core. Capa fina sobre el
 * CallsGateway común: toda decisión la toman Core (identidad, permisos, dispositivo) y TeamCalls (llamada).
 * Los comandos exigen la cabecera Idempotency-Key; la correlación es el request_id de Core.
 */
final class CollaborationCallsController
{
    /** Campos que el cliente nunca puede imponer. */
    private const UNTRUSTED = ['device_id', 'institution_id', 'initiator_id', 'room', 'room_name', 'identity', 'actor_id', 'session_id'];

    public function __construct(private readonly CallsGateway $calls) {}

    public function recent(Request $request): JsonResponse
    {
        return $this->page($request, fn (ActorContext $a, PageRequest $p) => $this->calls->recentFor($a, $p));
    }

    public function missed(Request $request): JsonResponse
    {
        return $this->page($request, fn (ActorContext $a, PageRequest $p) => $this->calls->missedFor($a, $p));
    }

    public function show(Request $request, string $call): JsonResponse
    {
        return $this->summary($request, $this->calls->find($this->actor($request), $call));
    }

    public function active(Request $request): JsonResponse
    {
        $input = $request->validate(['type' => 'required|string|in:direct,group,channel', 'id' => 'required|string|max:64']);
        $summary = $this->calls->activeFor($this->actor($request), new ConversationRef(ConversationType::from($input['type']), $input['id']));

        return $this->json($request, $summary !== null ? $this->callArray($summary) : null);
    }

    public function store(Request $request): JsonResponse
    {
        $this->rejectUntrusted($request);
        $input = $request->validate([
            'media' => 'required|string|in:audio,video',
            'user_ids' => 'required_without:conversation|array|min:1|max:24',
            'user_ids.*' => 'integer|min:1',
            'conversation' => 'required_without:user_ids|prohibits:user_ids|array:type,id',
            'conversation.type' => 'required_with:conversation|string|in:direct,group,channel',
            'conversation.id' => 'required_with:conversation|string|max:64',
            'invitees' => 'sometimes|array|max:24',
            'invitees.*' => 'integer|min:1',
        ]);
        $target = isset($input['conversation'])
            ? CallTarget::conversation(new ConversationRef(ConversationType::from($input['conversation']['type']), $input['conversation']['id']),
                array_values(array_map('intval', $input['invitees'] ?? [])))
            : CallTarget::users(array_values(array_unique(array_map('intval', $input['user_ids']))));

        return $this->summary($request, $this->calls->start($this->actor($request), $target, CallMedia::from($input['media']), $this->command($request)), 201);
    }

    public function invite(Request $request, string $call): JsonResponse
    {
        $this->rejectUntrusted($request);
        $input = $request->validate(['user_ids' => 'required|array|min:1|max:24', 'user_ids.*' => 'integer|min:1']);

        return $this->summary($request, $this->calls->invite($this->actor($request), $call,
            array_values(array_unique(array_map('intval', $input['user_ids']))), $this->command($request)));
    }

    public function command(Request $request): CommandMetadata
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/D', $key) !== 1) {
            throw new ContractException('invalid', 422);
        }

        return new CommandMetadata($key, $this->actor($request)->requestId);
    }

    /** accept | decline | cancel | join | leave | end */
    public function act(Request $request, string $call, string $action): JsonResponse
    {
        $this->rejectUntrusted($request);
        $actor = $this->actor($request);
        $command = $this->command($request);
        $summary = match ($action) {
            'accept' => $this->calls->accept($actor, $call, $command),
            'decline' => $this->calls->decline($actor, $call, $command),
            'cancel' => $this->calls->cancel($actor, $call, $command),
            'join' => $this->calls->join($actor, $call, $command),
            'leave' => $this->calls->leave($actor, $call, $command),
            'end' => $this->calls->end($actor, $call, $command),
            default => throw new ContractException('not_found', 404),
        };

        return $this->summary($request, $summary);
    }

    /** Credenciales de medios para ESTE dispositivo. Nunca se guardan ni se repiten por idempotencia. */
    public function credentials(Request $request, string $call): JsonResponse
    {
        $c = $this->calls->credentials($this->actor($request), $call);

        return $this->json($request, ['server_url' => $c->serverUrl, 'token' => $c->token, 'identity' => $c->identity,
            'expires_at' => $c->expiresAt->format(DATE_ATOM)]);
    }

    /** @param callable(ActorContext, PageRequest): CursorPage<CallSummary> $fn */
    private function page(Request $request, callable $fn): JsonResponse
    {
        $input = $request->validate(['cursor' => 'sometimes|string|max:4096', 'limit' => 'sometimes|integer|min:1|max:100']);
        $page = $fn($this->actor($request), new PageRequest($input['cursor'] ?? null, (int) ($input['limit'] ?? 20)));

        return response()->json(['data' => array_map(fn (CallSummary $s) => $this->callArray($s), $page->items),
            'meta' => ['cursor' => $page->nextCursor, 'request_id' => $this->actor($request)->requestId]], 200, ['Cache-Control' => 'no-store']);
    }

    private function summary(Request $request, CallSummary $summary, int $status = 200): JsonResponse
    {
        return $this->json($request, $this->callArray($summary), $status);
    }

    private function json(Request $request, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $this->actor($request)->requestId]], $status, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function callArray(CallSummary $s): array
    {
        return [
            'id' => $s->id,
            'media' => $s->media->value,
            'scope' => $s->scope->value,
            'status' => $s->status->value,
            'conversation' => $s->conversation !== null ? ['type' => $s->conversation->type->value, 'id' => $s->conversation->id] : null,
            'initiator_id' => $s->initiatorId,
            'participants' => array_map(fn (ParticipantSummary $p) => ['user_id' => $p->userId, 'role' => $p->role->value, 'state' => $p->state->value], $s->participants),
            'created_at' => $s->createdAt->format(DATE_ATOM),
            'answered_at' => $s->answeredAt?->format(DATE_ATOM),
            'ended_at' => $s->endedAt?->format(DATE_ATOM),
            'end_reason' => $s->endReason,
            'viewer_state' => $s->viewerState?->value,
        ];
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new ContractException('unauthenticated', 401);
        }

        return $actor;
    }

    private function rejectUntrusted(Request $request): void
    {
        foreach (self::UNTRUSTED as $field) {
            if ($request->has($field)) {
                throw new ContractException('invalid', 422);
            }
        }
    }
}
