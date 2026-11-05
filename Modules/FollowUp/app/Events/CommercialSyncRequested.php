<?php

declare(strict_types=1);

namespace Modules\FollowUp\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class CommercialSyncRequested implements ShouldDispatchAfterCommit
{
    public function __construct(public int $institutionId, public string $entity, public int $recordId) {}
}
