<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Data;

final readonly class DeliveryEnvelope
{
    public function __construct(public EventEnvelope $event, public DeliveryAudience $audience)
    {
        if ($event->institutionId !== $audience->institutionId) {
            throw new \InvalidArgumentException('Cross-institution audience.');
        }
    }
}
