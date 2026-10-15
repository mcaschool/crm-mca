<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Modules\TeamCalls\Exceptions\CallException;

/**
 * A quién se llama: una conversación (los participantes los decide el SERVIDOR con sus miembros) o
 * personas concretas (cada una se valida en el servidor: misma institución, activa y con acceso).
 */
final class CallTarget
{
    /** @param list<int> $userIds */
    private function __construct(public readonly ?ConversationRef $conversation, public readonly array $userIds) {}

    public static function conversation(ConversationRef $ref): self
    {
        return new self($ref, []);
    }

    /** @param array<int|string> $userIds */
    public static function users(array $userIds): self
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === [] || min($ids) < 1) {
            throw CallException::invalid('At least one valid user is required.');
        }
        sort($ids);

        return new self(null, $ids);
    }

    /** Huella estable del destino (detecta una clave de idempotencia reutilizada con otro destino). */
    public function fingerprint(): string
    {
        return $this->conversation !== null
            ? 'conversation:'.$this->conversation->type.':'.$this->conversation->id
            : 'users:'.implode(',', $this->userIds);
    }
}
