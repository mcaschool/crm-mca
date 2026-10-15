<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use App\Models\User;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * PUERTO (lo que TeamCalls necesita saber de una conversación). Lo implementará TeamChat a través del
 * contrato ConversationDirectory de CollaborationCore. Mientras no exista, el adaptador provisional
 * NO autoriza ninguna llamada desde conversaciones (deny-by-default).
 *
 * Todas las respuestas se calculan en el SERVIDOR: nunca se aceptan miembros enviados por el cliente.
 */
interface ConversationAccess
{
    /** Institución de la conversación, o null si no existe. */
    public function institutionOf(ConversationRef $ref): ?int;

    /** ¿Puede este usuario iniciar una llamada desde la conversación? */
    public function canStartCall(User $user, ConversationRef $ref): bool;

    /** ¿Es miembro activo de la conversación? (para invitar o incorporarse). */
    public function isMember(int $userId, ConversationRef $ref): bool;

    /** @return list<int> ids de los miembros activos de la conversación */
    public function memberIds(ConversationRef $ref): array;
}
