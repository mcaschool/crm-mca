<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\Presence;

/**
 * Estado «en llamada» hacia el PresenceService común. Solo se enlaza si Core lo registra (si no, sigue
 * NoopPresence). Se aplica DESPUÉS de confirmar, como pide el contrato: una transacción revertida no deja
 * a nadie marcado. La presencia nunca autoriza nada en TeamCalls.
 */
final class CollaborationPresence implements Presence
{
    public function __construct(private readonly PresenceService $presence, private readonly CurrentInstitution $institution) {}

    public function setInCall(int $userId, string $callId, int $ttlSeconds): void
    {
        $context = $this->context();
        if ($context !== null) {
            DB::afterCommit(fn () => $this->presence->setInCall($context, $userId, $callId, $ttlSeconds));
        }
    }

    public function clearInCall(int $userId, string $callId): void
    {
        $context = $this->context();
        if ($context !== null) {
            DB::afterCommit(fn () => $this->presence->clearInCall($context, $userId, $callId));
        }
    }

    private function context(): ?InstitutionContext
    {
        $id = $this->institution->id();

        return $id !== null && $id > 0 ? new InstitutionContext($id) : null;
    }
}
