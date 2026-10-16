<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Integration\CallPresenceCleanupAuthorizer;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Enums\ParticipantState;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;

/**
 * Puerto de CollaborationCore para la señal «en llamada» (contrato 1.1). TeamCalls es la autoridad:
 *  - authorize (setInCall): la llamada existe EN esa institución, sigue viva y la persona participa
 *    activamente (aceptada o unida) sin haber sido expulsada.
 *  - authorizeCleanup (clearInCall): asociación HISTÓRICA real institución/persona/llamada, aunque haya
 *    salido, la hayan expulsado o la llamada haya terminado (limpiar nunca concede nada).
 * El mismo código para «no existe» y «no permitido»: no se revela si la llamada existe.
 */
final class TeamCallsPresenceAuthorizer implements CallPresenceCleanupAuthorizer
{
    public function __construct(private readonly CurrentInstitution $institution) {}

    public function authorize(InstitutionContext $context, int $userId, string $callId): void
    {
        $ok = $this->institution->runFor($context->institutionId, function () use ($userId, $callId): bool {
            $call = Call::query()->whereKey($callId)->first();
            if (! $call instanceof Call || ! $call->status->isLive()) {
                return false;
            }
            $p = CallParticipant::query()->where('call_id', $call->id)->where('user_id', $userId)->first();

            return $p instanceof CallParticipant && $p->revoked_at === null
                && in_array($p->state, [ParticipantState::Accepted, ParticipantState::Joined], true);
        });
        if (! $ok) {
            throw new ContractException('forbidden', 403);
        }
    }

    public function authorizeCleanup(InstitutionContext $context, int $userId, string $callId): void
    {
        $ok = $this->institution->runFor($context->institutionId,
            fn (): bool => CallParticipant::query()->where('call_id', $callId)->where('user_id', $userId)->exists());
        if (! $ok) {
            throw new ContractException('forbidden', 403);
        }
    }
}
