<?php

declare(strict_types=1);

namespace Modules\FollowUp\Jobs;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\FollowUp\Services\FollowUpDraftService;

final class GenerateFollowUpDraft extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(int $institutionId, public int $actionId, public string $reservation)
    {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        app(FollowUpDraftService::class)->generate($this->actionId, $this->reservation);
    }
}
