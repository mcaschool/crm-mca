<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Contracts;

use Modules\CollaborationRealtime\Data\DeliveryEnvelope;

interface RealtimePublisher
{
    /** Worker only, after commit. Delivery at least once. Throw on transport failure for durable retry.
     * Revalidate audience, discard expired alerts, never use transport as history.
     */
    public function publish(DeliveryEnvelope $delivery): void;
}
