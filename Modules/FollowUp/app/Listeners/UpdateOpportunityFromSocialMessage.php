<?php

declare(strict_types=1);

namespace Modules\FollowUp\Listeners;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Services\OpportunityDetector;
use Modules\Social\Events\SocialMessageStored;

final class UpdateOpportunityFromSocialMessage
{
    public function __construct(
        private readonly CurrentInstitution $context,
        private readonly OpportunityDetector $detector,
    ) {}

    public function handle(SocialMessageStored $event): void
    {
        if ($event->direction !== 'inbound') {
            return;
        }

        $this->context->runFor($event->institutionId, fn () => $this->detector->recordProspectResponse(
            $event->conversationId,
            $event->messageId,
        ));
    }
}
