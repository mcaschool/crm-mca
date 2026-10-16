<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use App\Models\User;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\ConversationRef as SharedRef;
use Modules\CollaborationCore\Enums\ConversationAction;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * Puerto de conversaciones de TeamCalls sobre el ConversationDirectory común. Solo se enlaza si Core lo
 * registra; sin él sigue DenyAllConversationAccess (llamadas de conversación desactivadas).
 *
 * Cada consulta se hace en nombre del ACTOR de la operación en curso (OperationContext, fijado por
 * SharedCallsGateway). Sin actor —API web interna, tareas programadas— todo se deniega: fallo cerrado.
 * No hay listado de miembros de grupos ni canales: el contrato exige invitados elegidos y validados uno a
 * uno; en las directas TeamChat resuelve al otro miembro.
 */
final class ConversationDirectoryAccess implements ConversationAccess
{
    public function __construct(private readonly ConversationDirectory $directory, private readonly OperationContext $operation) {}

    public function institutionOf(ConversationRef $ref): ?int
    {
        return $this->ask(function (ActorContext $actor) use ($ref): ?int {
            $d = $this->directory->resolve($actor, $this->shared($ref));

            return $d->active ? $d->institutionId : null;
        }, null);
    }

    public function canStartCall(User $user, ConversationRef $ref): bool
    {
        return $this->ask(function (ActorContext $actor) use ($user, $ref): bool {
            if ($actor->userId !== (int) $user->id) {
                return false;
            }
            $this->directory->authorize($actor, $this->shared($ref), ConversationAction::StartCall);

            return true;
        }, false);
    }

    public function isMember(int $userId, ConversationRef $ref): bool
    {
        return $this->ask(fn (ActorContext $actor): bool => $this->directory->membership($actor, $this->shared($ref), $userId)->active, false);
    }

    /**
     * Solo en conversaciones DIRECTAS: TeamChat resuelve al otro miembro (eligibleInvitees con selección
     * vacía; contrato 1.1). En grupos y canales no hay lista implícita: la selección es explícita.
     */
    public function memberIds(ConversationRef $ref): array
    {
        if ($ref->type !== 'direct') {
            return [];
        }

        return $this->ask(fn (ActorContext $actor): array => array_map(
            fn ($u) => $u->id, $this->directory->eligibleInvitees($actor, $this->shared($ref), [])
        ), []);
    }

    private function shared(ConversationRef $ref): SharedRef
    {
        return new SharedRef(ConversationType::from($ref->type), $ref->id);
    }

    /**
     * @template T
     *
     * @param  callable(ActorContext): T  $fn
     * @param  T  $denied
     * @return T
     */
    private function ask(callable $fn, mixed $denied): mixed
    {
        $actor = $this->operation->actor();
        if ($actor === null) {
            return $denied;
        }
        try {
            return $fn($actor);
        } catch (ContractException) {
            return $denied;
        }
    }
}
