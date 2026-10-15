<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration;

use App\Models\User;
use Modules\TeamCalls\Contracts\CallsGateway;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\CallTarget;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * Implementación de CallsGateway: la única puerta de TeamCalls para otros módulos. Devuelve siempre
 * CallSummary (nunca modelos internos) y aplica las mismas reglas de seguridad que el servicio.
 */
final class TeamCallsGateway implements CallsGateway
{
    public function __construct(private readonly CallService $calls) {}

    public function start(User $by, CallTarget $target, CallMedia $media, string $idempotencyKey, ?string $deviceId = null): CallSummary
    {
        return CallSummary::from($this->calls->start($by, $target, $media, $idempotencyKey, $deviceId), $by->id);
    }

    public function find(string $callId, User $viewer): CallSummary
    {
        return $this->calls->find($viewer, $callId);
    }

    public function activeFor(ConversationRef $ref, User $viewer): ?CallSummary
    {
        $call = $this->calls->activeForConversation($viewer, $ref->type, $ref->id);

        return $call !== null ? CallSummary::from($call, $viewer->id) : null;
    }

    public function recentFor(User $user, int $limit = 20): array
    {
        return $this->calls->history($user, $limit);
    }

    public function missedFor(User $user, int $limit = 20): array
    {
        return $this->calls->missed($user, $limit);
    }

    public function revokeAccess(int $userId, string $reason = 'access_revoked'): int
    {
        return $this->calls->revokeAccess($userId, $reason);
    }
}
