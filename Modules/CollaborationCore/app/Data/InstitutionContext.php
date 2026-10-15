<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class InstitutionContext
{
    public function __construct(
        public int $institutionId,
    ) {
        Guard::id($institutionId);
    }

}
