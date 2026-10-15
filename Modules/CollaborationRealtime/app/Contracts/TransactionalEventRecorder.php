<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Contracts;

use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

interface TransactionalEventRecorder
{
    /** Must use the caller's active SQL transaction/connection; throw if absent or tenant mismatches.
     * Atomically records event and audience. Same ID/same content is a no-op; changed content conflicts.
     * No network I/O. Rollback MUST remove the record. Returns the stable event ID.
     */
    public function append(InstitutionContext $context, EventEnvelope $event, DeliveryAudience $audience): string;
}
