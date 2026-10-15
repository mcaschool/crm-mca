<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Illuminate\Support\Str;
use Modules\CollaborationCore\Data\ActorContext;

/**
 * Contexto de la operación en curso (uno por petición o trabajo: se registra como «scoped»):
 *  - ActorContext oficial cuando la operación llega por el CallsGateway compartido (si no, null);
 *  - correlation_id: el del comando o, si no lo hay, uno por petición (todos los hechos de una misma
 *    petición quedan correlacionados);
 *  - causation_id: el comando que causó los hechos (su clave de idempotencia), si existe.
 * Nunca se hidrata desde datos del cliente: lo fija el adaptador con el contexto ya validado.
 */
final class OperationContext
{
    private ?ActorContext $actor = null;

    private ?string $correlation = null;

    private ?string $causation = null;

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(?ActorContext $actor, string $correlationId, ?string $causationId, callable $fn): mixed
    {
        $previous = [$this->actor, $this->correlation, $this->causation];
        [$this->actor, $this->correlation, $this->causation] = [$actor, $correlationId, $causationId];
        try {
            return $fn();
        } finally {
            [$this->actor, $this->correlation, $this->causation] = $previous;
        }
    }

    public function actor(): ?ActorContext
    {
        return $this->actor;
    }

    public function correlationId(): string
    {
        return $this->correlation ??= (string) Str::ulid();
    }

    public function causationId(): ?string
    {
        return $this->causation;
    }
}
