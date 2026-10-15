<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use App\Models\User;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * PROVISIONAL hasta que TeamChat implemente ConversationDirectory (CollaborationCore). Sin TeamChat no
 * hay conversaciones que verificar, así que se deniega todo: nunca se confía en el cliente.
 */
final class DenyAllConversationAccess implements ConversationAccess
{
    public function institutionOf(ConversationRef $ref): ?int
    {
        return null;
    }

    public function canStartCall(User $user, ConversationRef $ref): bool
    {
        return false;
    }

    public function isMember(int $userId, ConversationRef $ref): bool
    {
        return false;
    }

    public function memberIds(ConversationRef $ref): array
    {
        return [];
    }
}
