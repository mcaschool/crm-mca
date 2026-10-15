<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class CommandMetadata
{
    public function __construct(
        public string $idempotencyKey,
        public string $correlationId,
    ) {
        Guard::token($idempotencyKey);
        if (strlen($idempotencyKey) < 8) {
            throw new \InvalidArgumentException('Idempotency keys require 8 to 64 characters.');
        }
        Guard::token($correlationId);
    }

}
