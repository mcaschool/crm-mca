<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Support\ConversationRef;

/** Conversaciones simuladas (lo que en producción responderá TeamChat a través de CollaborationCore). */
final class FakeConversationAccess implements ConversationAccess
{
    /** @var array<string, array{institution:int, members:list<int>, callers:list<int>}> */
    private array $conversations = [];

    /**
     * @param  list<int>  $members
     * @param  list<int>|null  $callers  quién puede iniciar llamadas (por defecto, todos los miembros)
     */
    public function add(ConversationRef $ref, int $institutionId, array $members, ?array $callers = null): void
    {
        $this->conversations[$ref->type.':'.$ref->id] = ['institution' => $institutionId, 'members' => $members, 'callers' => $callers ?? $members];
    }

    public function institutionOf(ConversationRef $ref): ?int
    {
        return $this->conversations[$ref->type.':'.$ref->id]['institution'] ?? null;
    }

    public function canStartCall(User $user, ConversationRef $ref): bool
    {
        return in_array($user->id, $this->conversations[$ref->type.':'.$ref->id]['callers'] ?? [], true);
    }

    public function isMember(int $userId, ConversationRef $ref): bool
    {
        return in_array($userId, $this->conversations[$ref->type.':'.$ref->id]['members'] ?? [], true);
    }

    public function memberIds(ConversationRef $ref): array
    {
        return $this->conversations[$ref->type.':'.$ref->id]['members'] ?? [];
    }
}
