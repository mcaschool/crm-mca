<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

final readonly class PageRequest
{
    public function __construct(
        public ?string $cursor = null,
        public int $limit = 20,
    ) {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor === '' || strlen($cursor) > 4096))) {
            throw new \InvalidArgumentException('Invalid page request.');
        }
    }

}
