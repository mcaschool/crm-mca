<?php

declare(strict_types=1);

namespace Modules\Social\Events;

final readonly class SocialConversationStateChanged
{
    public function __construct(public int $institutionId, public int $conversationId, public string $fromState, public string $toState, public string $reason) {}
}
