<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use App\Models\User;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\CallTarget;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * Lo que TeamCalls OFRECE a otros módulos (TeamChat) sin exponer su implementación interna.
 * Cuando exista CollaborationCore, su contrato CallsGateway se enlazará a esta implementación.
 */
interface CallsGateway
{
    public function start(User $by, CallTarget $target, CallMedia $media, string $idempotencyKey, ?string $deviceId = null): CallSummary;

    public function find(string $callId, User $viewer): CallSummary;

    public function activeFor(ConversationRef $ref, User $viewer): ?CallSummary;

    /** @return list<CallSummary> */
    public function recentFor(User $user, int $limit = 20): array;

    /** @return list<CallSummary> */
    public function missedFor(User $user, int $limit = 20): array;

    /**
     * CollaborationCore avisa de que una persona ya no puede colaborar (desactivada o sin permiso): se le
     * retira de todas sus llamadas vivas de la institución activa (expulsión con rotación de sala).
     */
    public function revokeAccess(int $userId, string $reason = 'access_revoked'): int;
}
