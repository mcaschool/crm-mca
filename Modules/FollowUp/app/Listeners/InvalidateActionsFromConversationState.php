<?php

declare(strict_types=1);

namespace Modules\FollowUp\Listeners;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Services\ActionInvalidator;
use Modules\Social\Events\SocialConversationStateChanged;

final class InvalidateActionsFromConversationState
{
    public function __construct(private readonly CurrentInstitution $context, private readonly ActionInvalidator $invalidator) {}

    public function handle(SocialConversationStateChanged $event): void
    {
        if ($event->toState === 'bot') {
            return;
        }
        $this->context->runFor($event->institutionId, fn () => $this->invalidator->forConversation($event->conversationId, 'conversation_'.$event->toState));
    }
}
