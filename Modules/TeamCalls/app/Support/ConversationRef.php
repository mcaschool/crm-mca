<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Modules\TeamCalls\Exceptions\CallException;

/**
 * Referencia a una conversación de TeamChat (contrato CollaborationCore: tipo + id). TeamCalls no
 * conoce su estructura interna: solo pregunta por ella a ConversationAccess.
 */
final class ConversationRef
{
    public const TYPES = ['direct', 'group', 'channel'];

    public function __construct(public readonly string $type, public readonly string $id)
    {
        if (! in_array($type, self::TYPES, true) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw CallException::invalid('Invalid conversation reference.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
