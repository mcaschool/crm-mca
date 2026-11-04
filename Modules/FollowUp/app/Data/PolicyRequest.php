<?php

declare(strict_types=1);

namespace Modules\FollowUp\Data;

use App\Models\User;
use Carbon\CarbonInterface;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpOpportunity;

final readonly class PolicyRequest
{
    public function __construct(
        public FollowUpOpportunity $opportunity,
        public string $stage,
        public string $channel,
        public string $purpose,
        public ?CarbonInterface $scheduledAt = null,
        public ?CarbonInterface $expiresAt = null,
        public ?int $whatsappTemplateId = null,
        public ?User $actor = null,
        public ?FollowUpAction $action = null,
    ) {}
}
