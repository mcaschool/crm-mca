<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class ConversationDescriptor
{
    public function __construct(
        public ConversationRef $ref,
        public int $institutionId,
        public bool $active,
        public string $accessVersion,
    ) {
        Guard::id($institutionId);
        Guard::token($accessVersion);
    }

}
