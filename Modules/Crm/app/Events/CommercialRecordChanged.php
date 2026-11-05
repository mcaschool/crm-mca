<?php

declare(strict_types=1);

namespace Modules\Crm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** IDs only; consumers reread committed state within the explicit institution. */
final readonly class CommercialRecordChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public int $institutionId, public string $entity, public int $recordId) {}
}
