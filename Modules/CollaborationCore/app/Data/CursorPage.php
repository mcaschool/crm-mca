<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

/** @template T */
final readonly class CursorPage
{
    /** @param list<T> $items */
    public function __construct(public array $items, public ?string $nextCursor)
    {
        if (! array_is_list($items) || $nextCursor === '') {
            throw new \InvalidArgumentException('Invalid page.');
        }
    }
}
