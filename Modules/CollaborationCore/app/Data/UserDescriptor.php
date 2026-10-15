<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class UserDescriptor
{
    public function __construct(
        public int $id,
        public int $institutionId,
        public string $displayName,
        public bool $active,
    ) {
        Guard::id($id);
        Guard::id($institutionId);
    }

}
