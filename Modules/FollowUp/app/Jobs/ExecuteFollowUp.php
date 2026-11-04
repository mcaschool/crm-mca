<?php

declare(strict_types=1);

namespace Modules\FollowUp\Jobs;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\FollowUp\Services\FollowUpExecutor;

final class ExecuteFollowUp extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(int $institutionId, public int $actionId)
    {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        app(FollowUpExecutor::class)->execute($this->actionId);
    }
}
