<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Integration;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationRealtime\Data\EventEnvelope;

/** Internal extension port. Domain owners implement current resource permissions and strict payload schemas. */
interface EventPolicy
{
    public function validate(EventEnvelope $event): void;

    public function allows(ActorContext $actor, EventEnvelope $event): bool;

    public function ephemeral(): bool;
}
