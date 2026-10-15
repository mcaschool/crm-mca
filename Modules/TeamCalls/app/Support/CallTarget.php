<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Support;

use Modules\TeamCalls\Exceptions\CallException;

/**
 * A quién se llama: una conversación o personas concretas; cada persona se valida en el SERVIDOR.
 * En una conversación de grupo o canal, $selection son los invitados elegidos (contrato común: nunca se
 * convierte en «invitar a todo el equipo»); vacía solo en la API interna heredada. En directa se
 * resuelve el otro miembro en el servidor.
 */
final class CallTarget
{
    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $selection
     */
    private function __construct(public readonly ?ConversationRef $conversation, public readonly array $userIds, public readonly array $selection = []) {}

    /** @param array<int|string> $selection invitados elegidos (grupos y canales) */
    public static function conversation(ConversationRef $ref, array $selection = []): self
    {
        $ids = array_values(array_unique(array_map('intval', $selection)));
        if ($ids !== [] && min($ids) < 1) {
            throw CallException::invalid('Invalid invitees.');
        }
        if ($ref->type === 'direct' && $ids !== []) {
            throw CallException::invalid('Direct recipients are resolved by the server.');
        }
        sort($ids);

        return new self($ref, [], $ids);
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
        if ($this->conversation === null) {
            return 'users:'.implode(',', $this->userIds);
        }
        $base = 'conversation:'.$this->conversation->type.':'.$this->conversation->id;

        return $this->selection !== [] ? $base.':'.implode(',', $this->selection) : $base;
    }
}
