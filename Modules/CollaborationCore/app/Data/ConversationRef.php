<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Support\Guard;

final readonly class ConversationRef
{
    public function __construct(
        public ConversationType $type,
        public string $id,
    ) {
        Guard::token($id);
    }

}
