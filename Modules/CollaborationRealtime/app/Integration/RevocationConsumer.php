<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Integration;

use Modules\CollaborationRealtime\Data\EventEnvelope;

/** Trusted TeamCalls service binding, no HTTP. Persist idempotent effects/intent in the current SQL transaction.
 * No network here. Throw on failure: the existing outbox retries. Event ID is the consumer idempotency key.
 * Revalidate current source state/generation before delayed effects. Never swallow a failed durable effect.
 */
interface RevocationConsumer
{
    public function consume(EventEnvelope $event): void;
}
